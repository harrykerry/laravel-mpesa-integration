<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generates an API key for a caller of this API.
 *
 * Keys live in MPESA_API_KEYS as a comma separated list, so each caller can
 * have its own and any one of them can be revoked by removing it.
 */
class GenerateApiKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mpesa:api-key
                            {--for= : Who the key is for, used only as a comment}
                            {--replace : Print the key on its own instead of appended to the keys already configured}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an API key for a frontend or operator to call this API with';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $key = 'mpk_'.Str::random(48);
        $existing = (array) config('mpesa.security.api_keys', []);

        $keys = $this->option('replace') || $existing === []
            ? [$key]
            : [...$existing, $key];

        $this->newLine();
        $this->components->info('API key generated. Copy the line below into your .env file.');

        if ($comment = $this->option('for')) {
            $this->line("<fg=gray># key for {$comment}, generated ".now()->toDateString().'</>');
        }

        $this->line('<fg=green>MPESA_API_KEYS='.implode(',', $keys).'</>');
        $this->newLine();

        $this->components->bulletList([
            'The key is shown once; it is not stored anywhere by this command.',
            'Give each caller its own key, so one can be revoked on its own.',
            'Callers send it as the X-API-Key header, or as a bearer token.',
            'Never ship it in browser JavaScript or a mobile app: have your own backend hold it and call this API.',
        ]);

        return self::SUCCESS;
    }
}
