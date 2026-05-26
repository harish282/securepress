<?php

declare(strict_types=1);

namespace PressSentinel\Core\Edition;

/**
 * Free distribution: every feature ships unlocked.
 */
final class FreeEditionAccess implements EditionAccess
{
    public const FILTER_IS_PRO = 'presssentinel.is_pro';

    public function isPro(): bool
    {
        $isPro = true;

        if (\function_exists('apply_filters')) {
            $isPro = (bool) \call_user_func('apply_filters', self::FILTER_IS_PRO, $isPro, $this);
        }

        return $isPro;
    }

    public function status(): EditionStatus
    {
        return EditionStatus::free();
    }
}
