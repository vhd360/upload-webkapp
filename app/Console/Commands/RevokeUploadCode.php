<?php

namespace App\Console\Commands;

use App\Models\AccessCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('upload:revoke {code : Der Zugangscode, zum Beispiel K4NP-7QWM}')]
#[Description('Widerruft einen Zugangscode für den Datei-Upload')]
class RevokeUploadCode extends Command
{
    public function handle(): int
    {
        $code = AccessCode::normalize((string) $this->argument('code'));
        $accessCode = AccessCode::query()->where('code', $code)->first();

        if ($accessCode === null) {
            $this->components->error('Dieser Zugangscode wurde nicht gefunden.');

            return self::FAILURE;
        }

        if ($accessCode->revoked_at !== null) {
            $this->components->warn($accessCode->formattedCode().' ist bereits widerrufen.');

            return self::SUCCESS;
        }

        $accessCode->forceFill(['revoked_at' => now()])->save();
        $this->components->info($accessCode->formattedCode().' wurde widerrufen.');

        return self::SUCCESS;
    }
}
