<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingPack;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさない面談パックの削除を拒否する例外（HTTP 409）。
 */
final class MeetingPackNotDeletableException extends ConflictHttpException
{
    public static function forPublished(): self
    {
        return new self('公開中の面談パックは削除できません。');
    }
}
