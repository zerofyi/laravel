<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;

class PushHostinger extends BasePushCommand
{
    protected $signature = 'push:hostinger
                            {--dry-run : Simulate deployment pipelines without editing server files}
                            {--debug   : Print comprehensive network and command execution outputs}';

    protected $description = 'Pushes updates to GitHub, builds local assets, and completes a manual deployment to Hostinger via optimized tar stream.';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->info('🚀 Initializing Complete Hostinger Pipeline Execution...');
        $this->line('');

        // Phase 1: Validate Environment configurations
        $env = $this->validateLocalEnvironment(true);
        if (!$env) {
            return Command::FAILURE;
        }

        // Phase 2: Compile Local Assets (Built exactly ONCE here)
        if (!$this->compileFrontendAssetsLocally()) {
            return Command::FAILURE;
        }

        // Phase 3: Synchronize with Local GitHub wizard via Sub-Command calling
        $this->info('🔄 Routing tasks into local Git setup wizard...');
        $gitWizardCode = $this->call('push:github', [
            '--dry-run' => $isDryRun,
            '--debug' => $this->option('debug'),
        ]);

        if ($gitWizardCode !== 0) {
            $this->error('❌ Deployment aborted. Core structural codebase syncing failed.');
            return Command::FAILURE;
        }

        // Phase 4: Local-to-Server SSH Handshake & Path Check
        $this->info('🔑 Initializing connection sequence with remote Hostinger node...');
        $absolutePath = "/home/{$env['ssh_user']}/domains/{$env['site_dir']}";

        $sshBase = sprintf(
            'ssh -p %d -o StrictHostKeyChecking=accept-new -o BatchMode=yes %s@%s',
            $env['ssh_port'],
            $env['ssh_user'],
            $env['ssh_host']
        );

        // Run validation check to see if target folder profile exists on the remote host
        $pathCheckCmd = "{$sshBase} " . escapeshellarg("test -d '{$absolutePath}' && echo 'exists' || echo 'missing'");
        $pathCheck = Process::run($pathCheckCmd);

        if (!$pathCheck->successful() || trim($pathCheck->output()) !== 'exists') {
            $this->error("❌ Error: Target deployment directory [{$absolutePath}] does not exist on your Hostinger server.");
            $this->line('💡 Please map and set up this domain directory correctly within your Hostinger control panel first.');
            return Command::FAILURE;
        }
        $this->info('✅ Production target directory path verified.');

        // Phase 5: Server-to-GitHub Identity Checking & Exchange
        if (!$this->resolveServerToGitHubTrust($env['repo_url'], $sshBase, $isDryRun)) {
            return Command::FAILURE;
        }

        // Phase 6: Code Syncing & Production Pipeline Optimization
        if (!$this->executeRemoteDeployment($env, $sshBase, $absolutePath, $isDryRun)) {
            return Command::FAILURE;
        }

        $this->line('');
        $this->info("🎉 Deployment successfully updated! Production live link: https://{$env['site_dir']}");
        return Command::SUCCESS;
    }

    private function resolveServerToGitHubTrust(string $repoUrl, string $sshBase, bool $isDryRun): bool
    {
        // Extract Git provider hostname pattern cleanly
        $host = '';
        if (preg_match('/@([^:]+):/', $repoUrl, $matches)) {
            $host = $matches[1];
        } elseif (preg_match('/https?:\/\/([^\/]+)/', $repoUrl, $matches)) {
            $host = $matches[1];
        }
        $host = $host ?: 'github.com';

        if ($isDryRun) {
            $this->info('[DRY RUN] Skip trust layer evaluation.');
            return true;
        }

        // 1. Conditionally add domain profile straight into hostinger known_hosts profile
        $this->info("🔍 Synchronizing host keys matching destination domain signature: {$host}");
        $scanCmd = "{$sshBase} " . escapeshellarg("mkdir -p ~/.ssh && chmod 700 ~/.ssh && if ! grep -q '{$host}' ~/.ssh/known_hosts 2>/dev/null; then ssh-keyscan -H '{$host}' >> ~/.ssh/known_hosts 2>/dev/null; fi");
        Process::run($scanCmd);

        // 2. Programmatically verify visibility profile using local test invocation
        $this->info('🔍 Resolving repository accessibility profile context...');
        $visibilityCheck = Process::run('git ls-remote -h ' . escapeshellarg($repoUrl));

        if ($visibilityCheck->successful()) {
            $this->info('✅ Public repository signature detected. Skipping authentication setup steps.');
            return true;
        }

        $this->warn('🔒 Private repository detected. Managing deployment keys on the server...');

        // 3. Check for existing server keys or generate an unpassphrased profile dynamically
        $keyCheckCmd = "{$sshBase} " . escapeshellarg("test -f ~/.ssh/id_ed25519 && echo 'exists' || (test -f ~/.ssh/id_rsa && echo 'rsa_exists' || echo 'missing')");
        $keyCheck = trim(Process::run($keyCheckCmd)->output());

        $keyPath = '~/.ssh/id_ed25519';
        if ($keyCheck === 'missing') {
            $this->info('🔑 Key files absent on host server. Generating fresh unpassphrased Ed25519 key pair...');
            $genCmd = "{$sshBase} " . escapeshellarg('ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519');
            Process::run($genCmd);
        } elseif ($keyCheck === 'rsa_exists') {
            $keyPath = '~/.ssh/id_rsa';
        }

        // Fetch public key footprint output string directly from the host filesystem
        $getPubCmd = "{$sshBase} " . escapeshellarg("cat {$keyPath}.pub");
        $publicKey = trim(Process::run($getPubCmd)->output());

        if (empty($publicKey)) {
            $this->error('❌ Failed to retrieve structural public key string from server configuration context.');
            return false;
        }

        // 4. Register Deployment Key onto GitHub automatically via token, or provide a manual alternative
        $token = env('GITHUB_API_TOKEN');
        if (!empty($token)) {
            $this->info('🤖 GITHUB_API_TOKEN found. Attempting automatic Deploy Key injection...');

            if (preg_match('/[:\/]([^\/]+)\/([^\/\.]+)/', $repoUrl, $repoMatches)) {
                $owner = $repoMatches[1];
                $repoName = $repoMatches[2];

                $apiUrl = "https://api.github.com/repos/{$owner}/{$repoName}/keys";
                $response = Http::withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => "Bearer {$token}",
                ])->post($apiUrl, [
                    'title' => 'Hostinger Server Deployment Key (v0.1 Auto-Generated)',
                    'key' => $publicKey,
                    'read_only' => true
                ]);

                if ($response->successful() || $response->status() === 422) {
                    $this->info('✅ Remote security trust chain verified (Deploy key active).');
                    return true;
                }
                $this->warn('⚠️  Automated token authentication registration failed. Reverting to manual layout.');
            }
        }

        // Manual validation fallback box
        $this->line('');
        $this->warn('📋 Action Required: Please append this server public key to your repository Deploy Keys:');
        $this->line("   👉 Navigate to: GitHub Repository → Settings → Deploy keys");
        $this->line('   👉 Click "Add deploy key", name it, paste the string below, and leave "Allow write access" UNCHECKED.');
        $this->line('');
        $this->line(str_repeat('-', 70));
        $this->info($publicKey);
        $this->line(str_repeat('-', 70));
        $this->line('');

        $this->confirmYN('Press ENTER after you have saved this deploy key to GitHub to continue execution...', true);
        return true;
    }

    private function executeRemoteDeployment(array $env, string $sshBase, string $absolutePath, bool $isDryRun): bool
    {
        if ($isDryRun) {
            $this->info('[DRY RUN] Sync pipeline simulated successfully.');
            return true;
        }

        // 1. Synchronize Codebase via Git
        $this->info('🔄 Synchronizing codebase versions on server target...');
        $repoStatusCmd = "{$sshBase} " . escapeshellarg("test -d '{$absolutePath}/.git' && echo 'pull' || echo 'clone'");
        $repoStatus = trim(Process::run($repoStatusCmd)->output());

        $branchCheck = Process::run('git branch --show-current');
        $branch = ($branchCheck->successful() && !empty(trim($branchCheck->output()))) ? trim($branchCheck->output()) : 'main';

        if ($repoStatus === 'clone') {
            $syncCmd = "{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && git clone -b {$branch} {$env['repo_url']} .");
        } else {
            $syncCmd = "{$sshBase} " . escapeshellarg("cd '{$absolutePath}' && git fetch origin && git checkout {$branch} && git pull origin {$branch}");
        }

        $syncProcess = Process::timeout(self::PROCESS_TIMEOUT)->run($syncCmd);
        if (!$syncProcess->successful()) {
            $this->error('❌ Codebase alignment step failed.');
            $this->printFormattedOutput('Sync Error Output', $syncProcess->errorOutput());
            return false;
        }
        $this->info('   ↳ Codebase successfully synced.');

        // 2. Synchronize Frontend Bundles via Windows-Safe Compressed Tar Pipeline
        if (is_dir(base_path('public/build'))) {
            $this->info('📤 Delivering compiled frontend bundles via compressed stream pipeline...');
            $remoteBuildPath = "{$absolutePath}/public/build";

            // Wipe old asset directories first to avoid folder layouts locking out transfers
            Process::run("{$sshBase} " . escapeshellarg("rm -rf '{$remoteBuildPath}' && mkdir -p '{$absolutePath}/public'"));

            // Stream compressed archive directly inside standard terminal inputs to defeat dup() socket bugs
            $tarCmd = sprintf(
                'tar -czf - -C ./public build | %s "tar -xzf - -C %s"',
                $sshBase,
                escapeshellarg($absolutePath . '/public')
            );

            $this->debug("Running Asset Sync Command: {$tarCmd}");
            $tarProcess = Process::run($tarCmd);

            if (!$tarProcess->successful()) {
                $this->error('❌ Asset synchronization pipeline failed completely.');
                $this->printFormattedOutput('Asset Stream Failure Logs', $tarProcess->errorOutput());
                return false;
            }

            $this->info('   ↳ Frontend assets synchronized successfully.');
        }

        // 3. Server Optimization Pipeline Execution (Strict Failure Loop / Circuit-Breaker Mode)
        $this->info('⚙️  Running production optimization pipeline over SSH...');

        $remoteCommands = [
            "Ensure App Directory Context" => "cd '{$absolutePath}'",
            "Install Dependencies"          => "cd '{$absolutePath}' && composer install --no-dev --optimize-autoloader --no-interaction",
            "Setup Environment Config"      => "cd '{$absolutePath}' && if [ ! -f .env ]; then cp .env.example .env && php artisan key:generate --quiet; fi",
            "Run Migrations"               => "cd '{$absolutePath}' && php artisan migrate --force",
            "Setup Storage Link"           => "cd '{$absolutePath}' && if [ ! -L public/storage ] && [ ! -d public/storage ]; then php artisan storage:link; fi",
            "Setup Public HTML Symlink"    => "cd '{$absolutePath}' && if [ ! -L public_html ] && [ ! -d public_html ]; then ln -s public public_html; fi",
            "Clear Optimization Cache"     => "cd '{$absolutePath}' && php artisan optimize:clear",
            "Warm Production Cache"        => "cd '{$absolutePath}' && php artisan optimize"
        ];

        foreach ($remoteCommands as $taskName => $commandString) {
            $this->debug("Executing task: {$taskName}");
            $execCmd = "{$sshBase} " . escapeshellarg($commandString);
            $process = Process::timeout(self::PROCESS_TIMEOUT)->run($execCmd);

            if (!$process->successful()) {
                $this->line('');
                $this->error("❌ Fatal Circuit-Breaker: Optimization step failed at [{$taskName}]. Stopping deployment.");
                $this->printFormattedOutput("{$taskName} Error Trace Log", $process->errorOutput());
                return false;
            } else {
                $this->info("   ↳ Step [{$taskName}] completed successfully.");
                if ($this->option('debug')) {
                    $this->printFormattedOutput("{$taskName} Output Trace", $process->output());
                }
            }
        }

        return true;
    }
}
