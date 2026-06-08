<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Http;
use Exception;

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

        try {
            // Phase 1: Parse and Validate environment parameter states
            $env = $this->validateLocalEnvironment(true);
            if (!$env) {
                return Command::FAILURE;
            }

            // Phase 2: Compile Frontend Assets Locally (Built exactly ONCE)
            if (!$this->compileFrontendAssetsLocally()) {
                return Command::FAILURE;
            }

            // Phase 3: Route Sub-Wizard task loops to manage Git configurations
            $this->info('🔄 Routing tasks into local Git setup wizard...');
            $gitWizardCode = $this->call('push:github', [
                '--dry-run' => $isDryRun,
                '--skip-assets' => true,
                '--debug' => $this->option('debug'),
            ]);

            if ($gitWizardCode !== 0) {
                $this->error('❌ Deployment aborted. Core structural codebase syncing failed.');
                return Command::FAILURE;
            }

            // Phase 4: Local-to-Server SSH Verification handshake
            $this->info('🔑 Initializing connection sequence with remote Hostinger node...');

            // Build absolute path targets with programmatic string sanitation guards
            $userClean = trim($env['ssh_user']);
            $dirClean = trim($env['site_dir']);
            $absolutePath = "/home/{$userClean}/domains/{$dirClean}";

            $sshBase = sprintf(
                'ssh -p %d -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=15 %s@%s',
                $env['ssh_port'],
                $userClean,
                trim($env['ssh_host'])
            );

            // Execute an explicit directory check to guarantee deployment targets exist
            $pathCheckCmd = "{$sshBase} " . escapeshellarg("test -d '{$absolutePath}' && echo 'exists' || echo 'missing'");
            $pathCheck = Process::run($pathCheckCmd);

            if (!$pathCheck->successful() || trim($pathCheck->output()) !== 'exists') {
                $this->line('');
                $this->error("❌ Error: Target deployment directory [{$absolutePath}] does not exist on your Hostinger server.");
                $this->line('💡 Please map and set up this domain directory correctly within your Hostinger control panel first.');
                return Command::FAILURE;
            }
            $this->info('✅ Production target directory path verified.');

            // Phase 5: Server-to-GitHub Trust Verification Engine
            if (!$this->resolveServerToGitHubTrust($env['repo_url'], $sshBase, $isDryRun)) {
                return Command::FAILURE;
            }

            // Phase 6: Code Syncing & Production Pipeline Optimization Execution
            if (!$this->executeRemoteDeployment($env, $sshBase, $absolutePath, $isDryRun)) {
                return Command::FAILURE;
            }

        } catch (Exception $e) {
            $this->line('');
            $this->error('❌ Fatal unhandled exception terminated the deployment pipeline: ' . $e->getMessage());
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
        $host = trim($host ?: 'github.com');

        if ($isDryRun) {
            $this->info('[DRY RUN] Skip trust layer evaluation.');
            return true;
        }

        // 1. Force feed hostname signatures safely into remote known_hosts mapping
        $this->info("🔍 Synchronizing host keys matching destination domain signature: {$host}");
        $scanCmd = "{$sshBase} " . escapeshellarg("mkdir -p ~/.ssh && chmod 700 ~/.ssh && if ! grep -q '{$host}' ~/.ssh/known_hosts 2>/dev/null; then ssh-keyscan -H '{$host}' >> ~/.ssh/known_hosts 2>/dev/null; fi");
        Process::run($scanCmd);

        // 2. Intercept repository configuration profile context visibility variables
        $this->info('🔍 Resolving repository accessibility profile context...');

        // FIX: Completely strip VS Code authentication injection by disabling terminal prompts,
        // credential helpers, and wiping the environmental variables VS Code uses to force login.
        $visibilityCheck = Process::env([
            'GITHUB_TOKEN'        => null,
            'GIT_ASKPASS'        => 'echo', // Bypasses VS Code's internal terminal askpass helper
            'GIT_TERMINAL_PROMPT' => '0'
        ])->run('git -c credential.helper= ls-remote -h ' . escapeshellarg($repoUrl));

        if ($visibilityCheck->successful()) {
            $this->info('✅ Public repository signature detected. Skipping authentication setup steps.');
            return true;
        }

        $this->warn('🔒 Private repository detected. Managing deployment keys on the server...');

        // 3. Check for existing server keys or generate an unpassphrased profile dynamically via RSA
        $keyCheckCmd = "{$sshBase} " . escapeshellarg("test -f ~/.ssh/id_rsa && echo 'exists' || echo 'missing'");
        $keyCheck = trim(Process::run($keyCheckCmd)->output());

        $keyPath = '~/.ssh/id_rsa';
        if ($keyCheck === 'missing') {
            $this->info('🔑 Key files absent on host server. Generating fresh unpassphrased RSA 4096-bit key pair...');

            // FIX: Using single-quoted -P '' fixes Hostinger's "Too many arguments" parsing bug
            $genCmd = "{$sshBase} " . escapeshellarg("mkdir -p ~/.ssh && chmod 700 ~/.ssh && ssh-keygen -t rsa -b 4096 -P '' -f ~/.ssh/id_rsa");
            $genProcess = Process::run($genCmd);

            if (!$genProcess->successful()) {
                $this->error('❌ Failed to execute key generation command on Hostinger.');
                $this->printFormattedOutput('Keygen Error Output', $genProcess->errorOutput());
                return false;
            }
        }

        // Fetch public key footprint output string directly from the host filesystem
        $getPubCmd = "{$sshBase} " . escapeshellarg("cat {$keyPath}.pub");
        $publicKey = trim(Process::run($getPubCmd)->output());

        if (empty($publicKey)) {
            $this->error('❌ Failed to retrieve structural public key string from server configuration context.');
            return false;
        }

        // Normalize Public Key: Strip host trail comments to fulfill precise API payload checks
        $keyParts = explode(' ', $publicKey);
        $normalizedKey = (count($keyParts) >= 2) ? $keyParts[0] . ' ' . $keyParts[1] : $publicKey;

        // 4. Inject public key data string directly into GitHub Repository configurations if a token is readily present
        $token = env('GITHUB_API_TOKEN');
        if (!empty($token)) {
            $this->info('🤖 GITHUB_API_TOKEN found. Attempting automatic Deploy Key injection...');

            if (preg_match('#https://github\.com/([^/]+)/([^/]+?)(?:\.git)?$#', trim($repoUrl), $repoMatches)) {
                $owner = $repoMatches[1];
                $repoName = $repoMatches[2];

                $apiUrl = "https://api.github.com/repos/{$owner}/{$repoName}/keys";
                $this->info($apiUrl);

                try {

                    // Check if key already exists on GitHub API first to prevent duplicate errors
                    $checkResponse = Http::withHeaders([
                        'Accept' => 'application/vnd.github.v3+json',
                        'Authorization' => "Bearer " . trim($token),
                    ])->get($apiUrl);

                    $this->debug('GitHub API Deploy Keys Check Response: ' . $checkResponse->body());

                    $alreadyLinked = false;
                    if ($checkResponse->successful()) {
                        foreach ($checkResponse->json() as $existingKey) {
                            $exParts = explode(' ', $existingKey['key']);
                            $normalizedExKey = (count($exParts) >= 2) ? $exParts[0] . ' ' . $exParts[1] : $existingKey['key'];
                            if ($normalizedExKey === $normalizedKey) {
                                $alreadyLinked = true;
                                break;
                            }
                        }
                    }

                    if ($alreadyLinked) {
                        $this->info('✅ Deploy key already recognized active on GitHub.');
                        return true;
                    }

                    $response = Http::timeout(15)->withHeaders([
                        'Accept' => 'application/vnd.github.v3+json',
                        'Authorization' => "Bearer " . trim($token),
                    ])->post($apiUrl, [
                        'title' => 'Hostinger Server Deployment Key',
                        'key' => $normalizedKey,
                        'read_only' => true
                    ]);

                    if ($response->successful() || $response->status() === 422) {
                        $this->info('✅ Remote security trust chain verified (Deploy key active).');
                        return true;
                    }
                } catch (Exception $e) {
                    $this->warn('⚠️  Automated token API registration handshake timed out.');
                }
                $this->warn('⚠️  Automated authentication registration failed. Reverting to manual fallback mode.');
            }
        }

        // Interactive manual key exchange console card layout
        $this->line('');
        $this->warn('📋 Action Required: Please append this server public key to your repository Deploy Keys:');
        $this->line("   👉 Navigate to: GitHub Repository → Settings → Deploy keys");
        $this->line('   👉 Click "Add deploy key", name it, paste the string below, and leave "Allow write access" UNCHECKED.');
        $this->line('');
        $this->line(str_repeat('-', 70));
        $this->info($publicKey);
        $this->line(str_repeat('-', 70));
        $this->line('');

        // Loop confirmation logic matching reference specification
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!$this->confirmYN("Press ENTER after you have saved this deploy key to GitHub to continue execution (Attempt {$attempt}/{$maxAttempts})", true)) {
                $this->error('❌ Deployment cancelled by user option.');
                return false;
            }

            $this->info('🔄 Testing remote server authentication credentials against GitHub...');
            $testCmd = "{$sshBase} " . escapeshellarg("ssh -T -o StrictHostKeyChecking=accept-new git@{$host} 2>&1");
            $testProcess = Process::run($testCmd);
            $testOutput = strtolower($testProcess->output());

            // GitHub successfully welcomes authenticated keys with a soft 1 exit code message
            if (str_contains($testOutput, 'successfully authenticated') || str_contains($testOutput, 'hi ')) {
                $this->info('✅ Key handshakes mapped successfully!');
                return true;
            }

            if ($attempt < $maxAttempts) {
                $this->warn('⚠️  GitHub rejected the connection. Double-check that the key is saved correctly.');
            }
        }

        $this->error('❌ Maximum key check attempts exhausted. Aborting deployment.');
        return false;
    }

    private function executeRemoteDeployment(array $env, string $sshBase, string $absolutePath, bool $isDryRun): bool
    {
        if ($isDryRun) {
            $this->info('[DRY RUN] Sync pipeline simulated successfully.');
            return true;
        }

        // 1. Synchronize application codebase layout via Git updates
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

        // 2. Synchronize frontend bundle directories via compressed Tarball streams over SSH
        if (is_dir(base_path('public/build'))) {
            $this->info('📤 Delivering compiled frontend bundles via compressed stream pipeline...');
            $remoteBuildPath = "{$absolutePath}/public/build";

            // Wipe old workspace structures to avoid file locking bugs
            Process::run("{$sshBase} " . escapeshellarg("rm -rf '{$remoteBuildPath}' && mkdir -p '{$absolutePath}/public'"));

            // Stream compressed binary pack data straight through standard terminal interface lines
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

        // 3. Complete remote Laravel deployment optimization framework (Strict Circuit-Breaker Loop)
        $this->info('⚙️  Running production optimization pipeline over SSH...');

        $remoteCommands = [
            "Ensure App Directory Context" => "cd '{$absolutePath}'",
            "Install Dependencies"          => "cd '{$absolutePath}' && composer install --no-dev --optimize-autoloader --no-interaction",
            "Setup Environment Config"      => "cd '{$absolutePath}' && if [ -f .env ]; then echo '👉 INFO: .env file already exists on Hostinger. Skipping creation safely.'; else if [ -f .env.example ]; then cp .env.example .env && php artisan key:generate --quiet && echo '✅ SUCCESS: Created fresh .env from .env.example'; else echo '⚠️ WARNING: .env.example is missing! Could not auto-generate .env'; fi; fi",
            "Run Migrations"               => "cd '{$absolutePath}' && php artisan migrate --force",
            "Setup Storage Link"           => "cd '{$absolutePath}' && if [ ! -L public/storage ] && [ ! -d public/storage ]; then php artisan storage:link; fi",
            "Setup Public HTML Symlink"    => "cd '{$absolutePath}' && rm -rf public_html && ln -sfn public public_html",
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
