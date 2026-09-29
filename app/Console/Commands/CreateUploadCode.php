<?php

namespace App\Console\Commands;

use App\Models\AccessCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('upload:code {label? : Bezeichnung, zum Beispiel der Name des Empfängers} {--expires= : Ablaufdatum im Format JJJJ-MM-TT}')]
#[Description('Erstellt einen Zugangscode für den Datei-Upload')]
class CreateUploadCode extends Command
{
    public function handle(): int
    {
        $expiresAt = $this->expiresAt();

        if ($expiresAt === false) {
            $this->components->error('Das Ablaufdatum muss im Format JJJJ-MM-TT angegeben werden.');

            return self::FAILURE;
        }

        $label = $this->argument('label');
        $accessCode = AccessCode::issue(is_string($label) && $label !== '' ? $label : null, $expiresAt);

        $this->components->info('Zugangscode erstellt');
        $this->components->twoColumnDetail('Code', $accessCode->formattedCode());
        $this->components->twoColumnDetail('Bezeichnung', $accessCode->label ?? '–');
        $this->components->twoColumnDetail('Gültig bis', $accessCode->expires_at?->format('d.m.Y') ?? 'ohne Ablauf');

        return self::SUCCESS;
    }

    private function expiresAt(): Carbon|false|null
    {
        $expires = $this->option('expires');

        if (! is_string($expires) || $expires === '') {
            return null;
        }

        try {
            $expiresAt = Carbon::createFromFormat('!Y-m-d', $expires);
        } catch (\Throwable) {
            return false;
        }

        if ($expiresAt === false) {
            return false;
        }

        return $expiresAt->endOfDay();
    }
}
