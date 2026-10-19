<?php

namespace Modules\JkControl\Services;

use App\Models\Worker;

/**
 * Finds the worker who owns an RFID card.
 *
 * Readers print the same UID in different shapes ("04:a1:b2", "04 A1 B2",
 * "04A1B2"), and people type it into the worker form by hand, so both sides are
 * normalised before comparing. The comparison runs in PHP rather than SQL for
 * that reason; with a few hundred workers that is cheap.
 */
class CardResolver
{
    public static function normalize(?string $uid): string
    {
        return preg_replace('/[^0-9A-Z]/', '', strtoupper(trim((string) $uid))) ?? '';
    }

    public function findWorker(string $uid): ?Worker
    {
        $needle = self::normalize($uid);

        if ($needle === '') {
            return null;
        }

        return Worker::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Worker $worker) => self::normalize($this->cardOf($worker)) === $needle);
    }

    private function cardOf(Worker $worker): ?string
    {
        if (config('jkcontrol.card_source') === 'code') {
            return $worker->code;
        }

        $value = $worker->cf(config('jkcontrol.card_custom_field', 'rfid_card'));

        return is_scalar($value) ? (string) $value : null;
    }
}
