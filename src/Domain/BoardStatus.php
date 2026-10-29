<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * One status of a board (broaden-use-cases issue 03) — see BoardStatuses.
 * `key` is what ideas.status stores; `category` is the canonical slug every
 * behaviour and the public contract hang on.
 */
final readonly class BoardStatus
{
    public function __construct(
        public string $key,
        public string $category,
        public bool $isSystem,
        public ?string $labelDe = null,
        public ?string $labelEn = null,
        public int $position = 0,
        public bool $isHidden = false,
        public bool $allowVotes = true,
        public bool $allowComments = true,
        public ?int $ideaCount = null,
    ) {}

    public function labelOverride(string $locale): ?string
    {
        return match ($locale) {
            'de'    => $this->labelDe,
            'en'    => $this->labelEn,
            default => null,
        };
    }

    /**
     * @return array{key: string, category: string, category_name: string, is_system: bool, label_de: ?string, label_en: ?string, is_hidden: bool, allow_votes: bool, allow_comments: bool, idea_count: ?int}
     */
    public function toArray(): array
    {
        return [
            'key'            => $this->key,
            'category'       => $this->category,
            'category_name'  => BoardStatuses::categoryName($this->category),
            'is_system'      => $this->isSystem,
            'label_de'       => $this->labelDe,
            'label_en'       => $this->labelEn,
            'is_hidden'      => $this->isHidden,
            'allow_votes'    => $this->allowVotes,
            'allow_comments' => $this->allowComments,
            'idea_count'     => $this->ideaCount,
        ];
    }
}
