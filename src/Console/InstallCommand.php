<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * `uptimex:install` — generate process-supervisor config for the
 * `uptimex:agent` daemon.
 *
 * The opt-in `agent` delivery mode needs the daemon kept alive by a process
 * supervisor. Installing a system service needs root, which an artisan
 * command does not have — so this command GENERATES the Supervisor program
 * file and the systemd unit (substituting this host's PHP binary, paths and
 * user) and prints the privileged copy-in steps, including the Laravel Forge
 * "Daemon" entry. The operator runs those once.
 */
class InstallCommand extends Command
{
    protected $signature = 'uptimex:install
        {--path= : Directory to write the generated config files to (default: storage/uptimex)}
        {--user= : OS user the agent process should run as (default: the current user)}';

    protected $description = 'Generate Supervisor + systemd config to run the uptimex:agent daemon under a process supervisor.';

    public function handle(): int
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $cwd = base_path();
        $user = $this->resolveUser();

        $outputDir = $this->resolveOutputDir();
        $supervisorPath = $outputDir.'/supervisor.conf';
        $systemdPath = $outputDir.'/uptimex-agent.service';

        $replacements = [
            '{{php}}' => $php,
            '{{artisan}}' => $artisan,
            '{{cwd}}' => $cwd,
            '{{user}}' => $user,
        ];

        try {
            File::ensureDirectoryExists($outputDir);

            $written = File::put($supervisorPath, $this->render('supervisor.conf.stub', $replacements)) !== false
                && File::put($systemdPath, $this->render('systemd.service.stub', $replacements)) !== false;
        } catch (Throwable $e) {
            $this->error("uptimex:install — could not write to {$outputDir}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $written) {
            $this->error("uptimex:install — could not write the config files to {$outputDir}. Check the directory is writable.");

            return self::FAILURE;
        }

        if (! config('uptimex.enabled', true) || empty(config('uptimex.token'))) {
            $this->warn('UptimeX has no token configured — the agent will idle until UPTIMEX_TOKEN is set.');
        }

        $this->info('uptimex:install — agent supervisor config generated:');
        $this->line("  Supervisor:  <info>{$supervisorPath}</info>");
        $this->line("  systemd:     <info>{$systemdPath}</info>");
        $this->newLine();
        $this->line('Pick the supervisor your server uses and run it once (needs sudo):');
        $this->newLine();
        $this->line('  <comment># Supervisor</comment>');
        $this->line("  sudo cp {$supervisorPath} /etc/supervisor/conf.d/uptimex-agent.conf");
        $this->line('  sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start uptimex-agent');
        $this->newLine();
        $this->line('  <comment># systemd</comment>');
        $this->line("  sudo cp {$systemdPath} /etc/systemd/system/uptimex-agent.service");
        $this->line('  sudo systemctl daemon-reload && sudo systemctl enable --now uptimex-agent');
        $this->newLine();
        $this->line('  <comment># Laravel Forge — Server → Daemons → New Daemon</comment>');
        $this->line("  Command:    {$php} {$artisan} uptimex:agent");
        $this->line("  Directory:  {$cwd}");
        $this->line("  User:       {$user}");
        $this->newLine();
        $this->line('Finally, set <info>UPTIMEX_DELIVERY=agent</info> in your .env, then run');
        $this->line('<info>php artisan uptimex:status</info> to confirm the agent is reachable.');

        return self::SUCCESS;
    }

    /**
     * The directory the generated config files are written to.
     */
    private function resolveOutputDir(): string
    {
        $path = trim((string) $this->option('path'));

        return $path !== '' ? rtrim($path, '/') : storage_path('uptimex');
    }

    /**
     * The OS user the agent process should run as — the --user option, else
     * the user running this command.
     */
    private function resolveUser(): string
    {
        $user = trim((string) $this->option('user'));
        if ($user !== '') {
            return $user;
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && ! empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        $current = get_current_user();

        return $current !== '' ? $current : ((string) getenv('USER') ?: 'www-data');
    }

    /**
     * Render a stub from the package's `stubs/` directory, substituting the
     * given `{{token}}` → value pairs.
     *
     * @param  array<string, string>  $replacements
     */
    private function render(string $stub, array $replacements): string
    {
        $template = (string) file_get_contents(__DIR__.'/../../stubs/'.$stub);

        return strtr($template, $replacements);
    }
}
