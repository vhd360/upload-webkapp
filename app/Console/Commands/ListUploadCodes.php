<?php

namespace App\Console\Commands;

use App\Models\AccessCode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('upload:codes')]
#[Description('Zeigt die Zugangscodes für den Datei-Upload')]
class ListUploadCodes extends Command
{
    public function handle(): int
    {
        $codes = AccessCode::query()->latest()->get();

        if ($codes->isEmpty()) {
            $this->components->info('Es gibt noch keine Zugangscodes.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Bezeichnung', 'Status', 'Gültig bis', 'Erstellt'],
            $codes->map(fn (AccessCode $accessCode): array => [
                $accessCode->formattedCode(),
                $accessCode->label ?? '–',
                $this->status($accessCode),
                $accessCode->expires_at?->format('d.m.Y') ?? 'ohne Ablauf',
                $accessCode->created_at?->format('d.m.Y H:i') ?? '–',
            ]),
        );

        return self::SUCCESS;
    }

    private function status(AccessCode $accessCode): string
    {
        if ($accessCode->revoked_at !== null) {
            return 'Widerrufen';
        }

        if ($accessCode->expires_at !== null && $accessCode->expires_at->isPast()) {
            return 'Abgelaufen';
        }

        return 'Aktiv';
    }
}
