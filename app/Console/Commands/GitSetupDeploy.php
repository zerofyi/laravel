<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Process as Git;

class GitSetupDeploy extends Command
{
    protected $signature = 'project:init-push
                            {--dry-run  : Simulate all steps without pushing to remote}
                            {--debug    : Print raw git output for every operation}
                            {--timeout= : Push timeout in seconds (default: 60)}';

    protected $description = 'Initialize a Git repository, commit pending changes, and push to GitHub via HTTPS.';

    private const DEFAULT_PUSH_TIMEOUT   = 60;
    private const GITHUB_HTTPS_PATTERN   = '/^https:\/\/github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(\.git)?$/';
    private const INITIAL_COMMIT_MESSAGE = 'Version 1.0.0 - Initial version';

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $timeout  = (int) ($this->option('timeout') ?: self::DEFAULT_PUSH_TIMEOUT);

        $this->printBanner($isDryRun);

        // Step 1 — Verify git binary
        if (! $this->isGitInstalled()) {
            $this->error('[ERROR] Git is not installed or not found in PATH.');
            $this->line('        Download and install Git from: https://git-scm.com');
            return Command::FAILURE;
        }

        $this->debug('Git binary: ' . $this->getGitVersion());

        // Step 2 — Ensure local repository exists
        if (! $this->isGitInitialized()) {
            $this->warn('[WARN]  This directory is not a Git repository.');

            if (! $this->confirmYN('Initialize a new Git repository here?', true)) {
                $this->error('[ERROR] Aborted. A Git repository is required to continue.');
                return Command::FAILURE;
            }

            if (! $this->initializeGitRepository($isDryRun)) {
                return Command::FAILURE;
            }
        } else {
            $this->line('[OK]    Git repository detected.');
        }

        // Step 3 — Stage and commit any pending changes
        if (! $this->handlePendingCommits($isDryRun)) {
            return Command::FAILURE;
        }

        // Step 4 — Resolve or register the remote origin
        $remoteUrl = $this->getRemoteUrl();

        if (! $remoteUrl) {
            $this->warn('[WARN]  No remote origin is configured.');
            $remoteUrl = $this->ask('Enter the GitHub HTTPS URL for this repository (e.g. https://github.com/user/repo.git)');

            if (! $remoteUrl) {
                $this->error('[ERROR] A remote URL is required.');
                return Command::FAILURE;
            }

            if (! $this->isValidGithubHttpsUrl($remoteUrl)) {
                $this->error('[ERROR] Invalid URL format. Only HTTPS GitHub URLs are accepted.');
                $this->line('        Expected: https://github.com/username/repository.git');
                return Command::FAILURE;
            }

            $this->line("[INFO]  Adding remote origin: {$remoteUrl}");

            if (! $isDryRun) {
                $result = Process::run('git remote add origin ' . escapeshellarg($remoteUrl));
                if (! $result->successful()) {
                    $this->error('[ERROR] Failed to add remote origin.');
                    $this->printGitOutput($result->errorOutput());
                    return Command::FAILURE;
                }
            }
        } else {
            $this->line("[OK]    Remote origin: {$remoteUrl}");

            if (! $this->isValidGithubHttpsUrl($remoteUrl)) {
                $this->warn('[WARN]  Remote URL does not match the expected GitHub HTTPS format.');
                $this->line("        Current: {$remoteUrl}");

                if (! $this->confirmYN('Continue with this URL anyway?', false)) {
                    return Command::FAILURE;
                }
            }
        }

        // Step 5 — Resolve current branch
        $branch = $this->currentBranch() ?: 'main';
        $this->line("[OK]    Active branch: {$branch}");

        // Step 6 — Confirm push
        $this->line('');
        $this->line('  Push summary');
        $this->line('  ------------');
        $this->line("  Remote  : {$remoteUrl}");
        $this->line("  Branch  : {$branch}");
        $this->line("  Timeout : {$timeout}s" . ($isDryRun ? '   [DRY RUN — nothing will be pushed]' : ''));
        $this->line('');

        if (! $this->confirmYN("Push branch [{$branch}] to origin?", true)) {
            $this->warn('[WARN]  Push cancelled.');
            return Command::FAILURE;
        }

        // Step 7 — Push
        return $this->pushToRemote($branch, $timeout, $isDryRun);
    }

    // -------------------------------------------------------------------------
    // Core steps
    // -------------------------------------------------------------------------

    /**
     * Run `git init`, stage all files, and create the initial commit.
     */
    private function initializeGitRepository(bool $isDryRun): bool
    {
        $this->line('[INFO]  Initializing local repository...');

        if (! $isDryRun) {
            $init = Process::run('git init');
            if (! $init->successful()) {
                $this->error('[ERROR] `git init` failed.');
                $this->printGitOutput($init->errorOutput());
                return false;
            }
        }

        $this->line('[INFO]  Staging all project files...');

        if (! $isDryRun) {
            Process::run('git add .');
        }

        $this->line('[INFO]  Creating initial commit: "' . self::INITIAL_COMMIT_MESSAGE . '"');

        if (! $isDryRun) {
            $commit = Process::run('git commit -m ' . escapeshellarg(self::INITIAL_COMMIT_MESSAGE));
            if (! $commit->successful()) {
                $this->error('[ERROR] Initial commit failed.');
                $this->printGitOutput($commit->errorOutput());
                return false;
            }
        }

        $this->line('[OK]    Repository initialized and initial commit created.');
        return true;
    }

    /**
     * Detect uncommitted changes, prompt for a commit message, and commit.
     */
    private function handlePendingCommits(bool $isDryRun): bool
    {
        $status = Process::run('git status --porcelain');

        if (! trim($status->output())) {
            $this->line('[OK]    Working directory is clean. Nothing to commit.');
            return true;
        }

        $this->warn('[WARN]  Uncommitted changes detected:');
        foreach (explode("\n", trim($status->output())) as $line) {
            $this->line('        ' . $line);
        }

        $message = $this->ask('Enter a commit message for these changes', 'Automated update');

        if (! $message) {
            $this->error('[ERROR] A commit message is required.');
            return false;
        }

        if (! $isDryRun) {
            Process::run('git add .');
            $commit = Process::run('git commit -m ' . escapeshellarg($message));

            if (! $commit->successful()) {
                $this->error('[ERROR] Commit failed.');
                $this->printGitOutput($commit->errorOutput());
                return false;
            }
        }

        $this->line('[OK]    Changes staged and committed.');
        return true;
    }

    /**
     * Push the current branch to origin and return the command exit code.
     */
    private function pushToRemote(string $branch, int $timeout, bool $isDryRun): int
    {
        if ($isDryRun) {
            $this->line('[DRY RUN] Push skipped. All preceding steps completed successfully.');
            return Command::SUCCESS;
        }

        $this->line("[INFO]  Pushing to origin/{$branch}...");

        $push = Process::env([
                'GIT_TERMINAL_PROMPT' => '0',        // Never prompt; fail immediately if auth is missing
                'GIT_SSH_COMMAND'     => 'ssh -o BatchMode=yes', // Fail-fast if SSH is invoked
            ])
            ->timeout($timeout)
            ->run('git push -u origin ' . escapeshellarg($branch));

        if ($this->option('debug')) {
            $this->printGitOutput($push->output());
        }

        if ($push->successful()) {
            $this->line('');
            $this->info('[OK]    Push successful. Branch is up to date on GitHub.');
            return Command::SUCCESS;
        }

        $this->error('[ERROR] Push failed.');

        $errOutput = trim($push->errorOutput());
        $errLower  = strtolower($errOutput);

        if (str_contains($errLower, 'authentication') || str_contains($errLower, 'could not read password') || str_contains($errLower, '403')) {
            $this->warn('[CAUSE] Authentication failed.');
            $this->line('        Run `gh auth login` or configure a Personal Access Token.');
            $this->line('        Manage tokens at: https://github.com/settings/tokens');
        } elseif (str_contains($errLower, 'repository not found') || str_contains($errLower, '404')) {
            $this->warn('[CAUSE] Repository not found.');
            $this->line('        Verify the remote URL is correct and the repository exists.');
            $this->line('        Current remote: ' . ($this->getRemoteUrl() ?? 'none'));
        } elseif (str_contains($errLower, 'permission denied') || str_contains($errLower, '401')) {
            $this->warn('[CAUSE] Permission denied.');
            $this->line('        Confirm you have write access to this repository.');
        } elseif (str_contains($errLower, 'timed out') || str_contains($errLower, 'connection refused') || str_contains($errLower, 'network')) {
            $this->warn('[CAUSE] Network or connection error.');
            $this->line('        Check your internet connection.');
            $this->line("        Consider increasing --timeout (current: {$timeout}s).");
        } elseif (str_contains($errLower, 'rejected') || str_contains($errLower, 'non-fast-forward')) {
            $this->warn('[CAUSE] Remote contains commits not present locally.');
            $this->line("        Run: git pull --rebase origin {$branch}");
        } else {
            $this->warn('[CAUSE] Unrecognized error. See git output below.');
        }

        $this->printGitOutput($errOutput);
        return Command::FAILURE;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Prompt with explicit (y/N) or (Y/n) hint instead of relying on confirm().
     */
    private function confirmYN(string $question, bool $default = false): bool
    {
        $hint   = $default ? '(Y/n)' : '(y/N)';
        $answer = $this->ask("{$question} {$hint}");

        if ($answer === null || $answer === '') {
            return $default;
        }

        return in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }

    private function isGitInstalled(): bool
    {
        $cmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where git' : 'which git';
        return Process::run($cmd)->successful();
    }

    private function isGitInitialized(): bool
    {
        $result = Process::run('git rev-parse --is-inside-work-tree');
        return $result->successful() && trim($result->output()) === 'true';
    }

    private function getRemoteUrl(): ?string
    {
        $result = Process::run('git config --get remote.origin.url');
        return $result->successful() ? trim($result->output()) : null;
    }

    private function currentBranch(): string
    {
        return trim(Process::run('git branch --show-current')->output());
    }

    private function getGitVersion(): string
    {
        return trim(Process::run('git --version')->output());
    }

    private function isValidGithubHttpsUrl(string $url): bool
    {
        return (bool) preg_match(self::GITHUB_HTTPS_PATTERN, $url);
    }

    private function printGitOutput(string $output): void
    {
        if (! $output) {
            return;
        }

        $this->line('');
        $this->line('  Git output:');
        foreach (explode("\n", trim($output)) as $line) {
            $this->line('  ' . $line);
        }
        $this->line('');
    }

    private function debug(string $message): void
    {
        if ($this->option('debug')) {
            $this->line("[DEBUG] {$message}");
        }
    }

    private function printBanner(bool $isDryRun): void
    {
        $this->line('');
        $this->line('  Git Deployment Wizard');
        $this->line('  ' . str_repeat('-', 40));

        if ($isDryRun) {
            $this->warn('  Mode: DRY RUN — no changes will be pushed');
        }

        $this->line('');
    }
}
