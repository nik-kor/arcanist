<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Interfaces with the Mercurial working copies.
 *
 * @group workingcopy
 */
final class ArcanistMercurialAPI extends ArcanistRepositoryAPI {

  private $status;
  private $base;
  private $relativeCommit;
  private $workingCopyRevision;
  private $localCommitInfo;
  private $includeDirectoryStateInDiffs;

  protected function buildLocalFuture(array $argv) {

    // Mercurial has a "defaults" feature which basically breaks automation by
    // allowing the user to add random flags to any command. This feature is
    // "deprecated" and "a bad idea" that you should "forget ... existed"
    // according to project lead Matt Mackall:
    //
    //  http://markmail.org/message/hl3d6eprubmkkqh5
    //
    // There is an HGPLAIN environmental variable which enables "plain mode"
    // and hopefully disables this stuff.

    $argv[0] = 'HGPLAIN=1 hg '.$argv[0];

    $future = newv('ExecFuture', $argv);
    $future->setCWD($this->getPath());
    return $future;
  }

  public function getSourceControlSystemName() {
    return 'hg';
  }

  public function getSourceControlBaseRevision() {
    return $this->getCanonicalRevisionName($this->getRelativeCommit());
  }

  public function getCanonicalRevisionName($string) {
    list($stdout) = $this->execxLocal(
      'log -l 1 --template %s -r %s --',
      '{node}',
      $string);
    return $stdout;
  }

  public function getSourceControlPath() {
    return '/';
  }

  public function getBranchName() {
    // TODO: I have nearly no idea how hg branches work.
    list($stdout) = $this->execxLocal('branch');
    return trim($stdout);
  }

  public function setRelativeCommit($commit) {
    try {
      $commit = $this->getCanonicalRevisionName($commit);
    } catch (Exception $ex) {
      throw new ArcanistUsageException(
        "Commit '{$commit}' is not a valid Mercurial commit identifier.");
    }

    $this->relativeCommit = $commit;
    $this->dropCaches();

    return $this;
  }

  public function getRelativeCommit() {
    if (empty($this->relativeCommit)) {
      list($err, $stdout) = $this->execManualLocal(
        'outgoing --branch `hg branch` --style default');

      if (!$err) {
        $logs = ArcanistMercurialParser::parseMercurialLog($stdout);
      } else {
        // Mercurial (in some versions?) raises an error when there's nothing
        // outgoing.
        $logs = array();
      }

      if (!$logs) {
        // In Mercurial, we support operations against uncommitted changes.
        return $this->getWorkingCopyRevision();
      }

      $outgoing_revs = ipull($logs, 'rev');

      // This is essentially an implementation of a theoretical `hg merge-base`
      // command.
      $against = $this->getWorkingCopyRevision();
      while (true) {
        // NOTE: The "^" and "~" syntaxes were only added in hg 1.9, which is
        // new as of July 2011, so do this in a compatible way. Also, "hg log"
        // and "hg outgoing" don't necessarily show parents (even if given an
        // explicit template consisting of just the parents token) so we need
        // to separately execute "hg parents".

        list($stdout) = $this->execxLocal(
          'parents --style default --rev %s',
          $against);
        $parents_logs = ArcanistMercurialParser::parseMercurialLog($stdout);

        list($p1, $p2) = array_merge($parents_logs, array(null, null));

        if ($p1 && !in_array($p1['rev'], $outgoing_revs)) {
          $against = $p1['rev'];
          break;
        } else if ($p2 && !in_array($p2['rev'], $outgoing_revs)) {
          $against = $p2['rev'];
          break;
        } else if ($p1) {
          $against = $p1['rev'];
        } else {
          // This is the case where you have a new repository and the entire
          // thing is outgoing; Mercurial literally accepts "--rev null" as
          // meaning "diff against the empty state".
          $against = 'null';
          break;
        }
      }

      $this->setRelativeCommit($against);
    }
    return $this->relativeCommit;
  }

  public function getLocalCommitInformation() {
    if ($this->localCommitInfo === null) {
      list($info) = $this->execxLocal(
        'log --style default --rev %s..%s --',
        $this->getRelativeCommit(),
        $this->getWorkingCopyRevision());
      $logs = ArcanistMercurialParser::parseMercurialLog($info);

      // Get rid of the first log, it's not actually part of the diff. "hg log"
      // is inclusive, while "hg diff" is exclusive.
      array_shift($logs);

      // Expand short hashes (12 characters) to full hashes (40 characters) by
      // issuing a big "hg log" command. Possibly we should do this with parents
      // too, but nothing uses them directly at the moment.
      if ($logs) {
        $cmd = array();
        foreach (ipull($logs, 'rev') as $rev) {
          $cmd[] = csprintf('--rev %s', $rev);
        }

        list($full) = $this->execxLocal(
          'log --template %s %C --',
          '{node}\\n',
          implode(' ', $cmd));

        $full = explode("\n", trim($full));
        foreach ($logs as $key => $dict) {
          $logs[$key]['rev'] = array_pop($full);
        }
      }
      $this->localCommitInfo = $logs;
    }

    return $this->localCommitInfo;
  }

  public function getBlame($path) {
    list($stdout) = $this->execxLocal(
      'annotate -u -v -c --rev %s -- %s',
      $this->getRelativeCommit(),
      $path);

    $blame = array();
    foreach (explode("\n", trim($stdout)) as $line) {
      if (!strlen($line)) {
        continue;
      }

      $matches = null;
      $ok = preg_match('/^\s*([^:]+?) [a-f0-9]{12}: (.*)$/', $line, $matches);

      if (!$ok) {
        throw new Exception("Unable to parse Mercurial blame line: {$line}");
      }

      $revision = $matches[2];
      $author = trim($matches[1]);
      $blame[] = array($author, $revision);
    }

    return $blame;
  }

  public function getWorkingCopyStatus() {

    if (!isset($this->status)) {
      // A reviewable revision spans multiple local commits in Mercurial, but
      // there is no way to get file change status across multiple commits, so
      // just take the entire diff and parse it to figure out what's changed.

      $diff = $this->getFullMercurialDiff();

      if (!$diff) {
        $this->status = array();
        return $this->status;
      }

      $parser = new ArcanistDiffParser();
      $changes = $parser->parseDiff($diff);

      $status_map = array();

      foreach ($changes as $change) {
        $flags = 0;
        switch ($change->getType()) {
          case ArcanistDiffChangeType::TYPE_ADD:
          case ArcanistDiffChangeType::TYPE_MOVE_HERE:
          case ArcanistDiffChangeType::TYPE_COPY_HERE:
            $flags |= self::FLAG_ADDED;
            break;
          case ArcanistDiffChangeType::TYPE_CHANGE:
          case ArcanistDiffChangeType::TYPE_COPY_AWAY: // Check for changes?
            $flags |= self::FLAG_MODIFIED;
            break;
          case ArcanistDiffChangeType::TYPE_DELETE:
          case ArcanistDiffChangeType::TYPE_MOVE_AWAY:
          case ArcanistDiffChangeType::TYPE_MULTICOPY:
            $flags |= self::FLAG_DELETED;
            break;
        }
        $status_map[$change->getCurrentPath()] = $flags;
      }

      list($stdout) = $this->execxLocal('status');

      $working_status = ArcanistMercurialParser::parseMercurialStatus($stdout);
      foreach ($working_status as $path => $status) {
        if ($status & ArcanistRepositoryAPI::FLAG_UNTRACKED) {
          // If the file is untracked, don't mark it uncommitted.
          continue;
        }
        $status |= self::FLAG_UNCOMMITTED;
        if (!empty($status_map[$path])) {
          $status_map[$path] |= $status;
        } else {
          $status_map[$path] = $status;
        }
      }

      $this->status = $status_map;
    }

    return $this->status;
  }

  private function getDiffOptions() {
    $options = array(
      '--git',
      // NOTE: We can't use "--color never" because that flag is provided
      // by the color extension, which may or may not be enabled. Instead,
      // set the color mode configuration so that color is disabled regardless
      // of whether the extension is present or not.
      '--config color.mode=off',
      '-U'.$this->getDiffLinesOfContext(),
    );
    return implode(' ', $options);
  }

  public function getRawDiffText($path) {
    $options = $this->getDiffOptions();

    list($stdout) = $this->execxLocal(
      'diff %C --rev %s --rev %s -- %s',
      $options,
      $this->getRelativeCommit(),
      $this->getDiffToRevision(),
      $path);

    return $stdout;
  }

  public function getFullMercurialDiff() {
    $options = $this->getDiffOptions();

    list($stdout) = $this->execxLocal(
      'diff %C --rev %s --rev %s --',
      $options,
      $this->getRelativeCommit(),
      $this->getDiffToRevision());

    return $stdout;
  }

  public function getOriginalFileData($path) {
    return $this->getFileDataAtRevision($path, $this->getRelativeCommit());
  }

  public function getCurrentFileData($path) {
    return $this->getFileDataAtRevision(
      $path,
      $this->getWorkingCopyRevision());
  }

  private function getFileDataAtRevision($path, $revision) {
    list($err, $stdout) = $this->execManualLocal(
      'cat --rev %s -- %s',
      $revision,
      $path);
    if ($err) {
      // Assume this is "no file at revision", i.e. a deleted or added file.
      return null;
    } else {
      return $stdout;
    }
  }

  public function getWorkingCopyRevision() {
    if ($this->workingCopyRevision === null) {
      // In Mercurial, "tip" means the tip of the current branch, not what's in
      // the working copy. The tip may be ahead of the working copy. We need to
      // use "hg summary" to figure out what is actually in the working copy.
      // For instance, "hg up 4 && arc diff" should not show commits 5 and
      // above.

      // Without arguments, "hg id" shows the current working directory's
      // commit, and "--debug" expands it to a 40-character hash.
      list($stdout) = $this->execxLocal('--debug id --id');

      // Even with "--id", "hg id" will print a trailing "+" after the hash
      // if the working copy is dirty (has uncommitted changes). We'll
      // explicitly detect this later by calling getWorkingCopyStatus(); ignore
      // it for now.
      $stdout = trim($stdout);
      $this->workingCopyRevision = rtrim($stdout, '+');
    }
    return $this->workingCopyRevision;
  }

  public function supportsRelativeLocalCommits() {
    return true;
  }

  public function hasLocalCommit($commit) {
    try {
      $this->getCanonicalRevisionName($commit);
      return true;
    } catch (Exception $ex) {
      return false;
    }
  }

  public function parseRelativeLocalCommit(array $argv) {
    if (count($argv) == 0) {
      return;
    }
    if (count($argv) != 1) {
      throw new ArcanistUsageException("Specify only one commit.");
    }
    // This does the "hg id" call we need to normalize/validate the revision
    // identifier.
    $this->setRelativeCommit(reset($argv));
  }

  public function getAllLocalChanges() {
    $diff = $this->getFullMercurialDiff();
    if (!strlen(trim($diff))) {
      return array();
    }
    $parser = new ArcanistDiffParser();
    return $parser->parseDiff($diff);
  }

  public function supportsLocalBranchMerge() {
    return true;
  }

  public function performLocalBranchMerge($branch, $message) {
    if ($branch) {
      $err = phutil_passthru(
        '(cd %s && HGPLAIN=1 hg merge --rev %s && hg commit -m %s)',
        $this->getPath(),
        $branch,
        $message);
    } else {
      $err = phutil_passthru(
        '(cd %s && HGPLAIN=1 hg merge && hg commit -m %s)',
        $this->getPath(),
        $message);
    }

    if ($err) {
      throw new ArcanistUsageException("Merge failed!");
    }
  }

  public function getFinalizedRevisionMessage() {
    return "You may now push this commit upstream, as appropriate (e.g. with ".
           "'hg push' or by printing and faxing it).";
  }

  public function getCommitMessageLog() {
    list($stdout) = $this->execxLocal(
      "log --template '{node}\\1{desc}\\0' --rev %s..%s --",
      $this->getRelativeCommit(),
      $this->getWorkingCopyRevision());

    $map = array();

    $logs = explode("\0", trim($stdout));
    foreach (array_filter($logs) as $log) {
      list($node, $desc) = explode("\1", $log);
      $map[$node] = $desc;
    }

    return array_reverse($map);
  }

  public function loadWorkingCopyDifferentialRevisions(
    ConduitClient $conduit,
    array $query) {

    // Try to find revisions by hash.
    $hashes = array();
    foreach ($this->getLocalCommitInformation() as $commit) {
      $hashes[] = array('hgcm', $commit['rev']);
    }

    $results = $conduit->callMethodSynchronous(
      'differential.query',
      $query + array(
        'commitHashes' => $hashes,
      ));

    return $results;
  }

  public function updateWorkingCopy() {
    $this->execxLocal('up');
  }

  public function setIncludeDirectoryStateInDiffs($include) {
    $this->includeDirectoryStateInDiffs = $include;
    return $this;
  }

  private function getDiffToRevision() {
    $this->dropCaches();

    if ($this->includeDirectoryStateInDiffs) {
      // This is a magic Mercurial revision name which means "current
      // directory state"; see lookup() in localrepo.py.
      return '.';
    } else {
      return $this->getWorkingCopyRevision();
    }
  }

  private function dropCaches() {
    $this->status = null;
    $this->localCommitInfo = null;
  }

}
