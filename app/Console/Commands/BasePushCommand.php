<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Process\InvokingProcessException;

abstract class BasePushCommand extends Command
{
    /**
     * Shared Process Runtime Config
     */
    protected const PROCESS_TIMEOUT = 300; // 5 Minutes max for npm/composer

    /**
     * Parse and validate required local .env variables.
     */
    protected function validateLocalEnvironment(bool $requireHostinger = false): ?array
    {
        $config = [
            'repo_url' => env('GITHUB_REPO_URL'),
        ];

        if (empty($config['repo_url'])) {
            $this->error('❌ Missing required environment variable: GITHUB_REPO_URL');
            $this->line('💡 Please add the following entry to your local .env file:');
            $this->warn('   GITHUB_REPO_URL=https://github.com/username/repository.git');
            return null;
        }

        if ($requireHostinger) {
            $config['ssh_host'] = env('HOSTINGER_SSH_HOST');
            $config['ssh_user'] = env('HOSTINGER_SSH_USERNAME');
            $config['ssh_port'] = (int) env('HOSTINGER_SSH_PORT', 22);
            $config['site_dir'] = env('HOSTINGER_SITE_DIR');

            $missing = [];
            foreach (['ssh_host' => 'HOSTINGER_SSH_HOST', 'ssh_user' => 'HOSTINGER_SSH_USERNAME', 'site_dir' => 'HOSTINGER_SITE_DIR'] as $key => $envKey) {
                if (empty($config[$key])) {
                    $missing[] = $envKey;
                }
            }

            if (!empty($missing)) {
                $this->error('❌ Missing required Hostinger environment variables.');
                $this->line('💡 Please add these missing entries to your local .env file:');
                foreach ($missing as $envKey) {
                    $this->warn("   {$envKey}=value");
                }
                return null;
            }
        }

        return $config;
    }

    /**
     * Step 2 — Check and Complete Local Frontend Assets
     */
    protected function compileFrontendAssetsLocally(): bool
    {
        if (!file_exists(base_path('package.json'))) {
            $this->debug('No package.json detected. Skipping frontend asset pipeline.');
            return true;
        }

        $this->info('📦 package.json detected. Verifying local Node environment...');

        // Verify if npm binary is accessible
        $npmCheckCmd = str_starts_with(strtoupper(PHP_OS), 'WIN') ? 'where npm' : 'which npm';
        $npmCheck = Process::run($npmCheckCmd);

        if (!$npmCheck->successful()) {
            $this->warn('⚠️  npm binary not found on your local computer. Skipping asset compilation.');
            return true;
        }

        // Install dependencies if node_modules is missing
        if (!is_dir(base_path('node_modules'))) {
            $this->info('📥 Local node_modules missing. Running npm install...');
            $install = Process::timeout(self::PROCESS_TIMEOUT)->path(base_path())->run('npm install');

            if (!$install->successful()) {
                $this->error('❌ Local "npm install" failed.');
                $this->line($install->errorOutput());
                return false;
            }
        }

        // Run production build
        $this->info('🔨 Compiling frontend assets via npm run build...');
        $build = Process::timeout(self::PROCESS_TIMEOUT)->path(base_path())->run('npm run build');

        if (!$build->successful()) {
            $this->error('❌ Local frontend compilation failed.');
            $this->line($build->errorOutput());
            return false;
        }

        $this->info('✅ Frontend assets built successfully.');
        return true;
    }

    /**
     * Explicit (y/N) or (Y/n) input matching helper.
     */
    protected function confirmYN(string $question, bool $default = false): bool
    {
        $hint = $default ? '(Y/n)' : '(y/N)';
        $answer = $this->ask("{$question} {$hint}");

        if ($answer === null || $answer === '') {
            return $default;
        }

        return in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }

    /**
     * Print verbose console lines if debug flag is present.
     */
    protected function debug(string $message): void
    {
        if ($this->option('debug')) {
            $this->line("[DEBUG] {$message}");
        }
    }

    /**
     * Split and neatly indent console blocks.
     */
    protected function printFormattedOutput(string $title, string $output): void
    {
        if (empty(trim($output))) {
            return;
        }

        $this->line('');
        $this->line("  {$title}:");
        foreach (explode("\n", trim($output)) as $line) {
            $this->line('  ' . $line);
        }
        $this->line('');
    }
}
