<?php

declare(strict_types=1);

/** Shared title rules so both storage backends reject exactly the same input. */
trait ValidatesTitle
{
    public const MAX_TITLE_LENGTH = 200;

    private function normalizeTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        if ($title === '') {
            throw new InvalidArgumentException('Title must not be empty.');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new InvalidArgumentException(
                'Title must not be longer than ' . self::MAX_TITLE_LENGTH . ' characters.'
            );
        }

        return $title;
    }
}
