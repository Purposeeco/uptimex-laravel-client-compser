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
 * supervisor. A system service must be installed as root, which an artisan
 * command cannot do — so this command GENERATES the Supervisor program file
 * and the systemd unit (with this host's PHP binary, paths and user already
 * substituted in) and prints the exact next steps, including the Laravel
 * Forge "Daemon" entry. The operator runs those once on the production server.
 *
 * It is a production tool — local development needs none of this; `direct`
 * delivery works with zero setup.
 */
class InstallCommand extends Command
{
    protected $signature = 'uptimex:install
        {--path= : Directory to write the generated config files to (default: storage/uptimex)}
        {--user= : OS user the agent process should run as (default: the current user)}';

    protected $description = 'Generate Supervisor / systemd config to run the uptimex:agent daemon on a production server.';

    public function handle(): int
    {
        $this->info('uptimex:install — production setup for the uptimex:agent daemon.');

        if ($this->getLaravel()->environment('local')) {
            $this->newLine();
            $this->warn('Heads up: this looks like a local environment. You do NOT need the agent');
            $this->warn('in local development — `direct` delivery works with zero setup. This');
            $this->warn('command is for production servers. Generating the files anyway.');
        }

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
            $this->warn('UptimeX has no token configured — set UPTIMEX_TOKEN before starting the agent.');
        }

        $this->newLine();
        $this->info('Generated:');
        $this->line("  Supervisor:  <info>{$supervisorPath}</info>");
        $this->line("  systemd:     <info>{$systemdPath}</info>");
        $this->newLine();
        $this->line('On your production server, pick the supervisor it uses:');
        $this->newLine();
        $this->line('  <comment># Laravel Forge — Server → Daemons → New Daemon (no file needed)</comment>');
        $this->line("  Command:    {$php} {$artisan} uptimex:agent");
        $this->line("  Directory:  {$cwd}");
        $this->line("  User:       {$user}");
        $this->newLine();
        $this->line('  <comment># Supervisor</comment>');
        $this->line("  sudo cp {$supervisorPath} /etc/supervisor/conf.d/uptimex-agent.conf");
        $this->line('  sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start uptimex-agent');
        $this->newLine();
        $this->line('  <comment># systemd</comment>');
        $this->line("  sudo cp {$systemdPath} /etc/systemd/system/uptimex-agent.service");
        $this->line('  sudo systemctl daemon-reload && sudo systemctl enable --now uptimex-agent');
        $this->newLine();
        $this->line('Then set <info>UPTIMEX_DELIVERY=agent</info> in .env and run <info>php artisan uptimex:status</info>.');

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
