<?php

declare(strict_types=1);

namespace Cronos\Appearance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class ThemeVersionModel extends Model implements AuditableContract
{
    use Auditable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'theme_versions';

    protected $guarded = [];

    /** @var list<string> The documents are big and every save would be a wall of JSON: the audit keeps who changed which version and when. */
    protected $auditExclude = ['tokens', 'assets', 'navigation', 'home', 'features'];

    protected function casts(): array
    {
        return ['tokens' => 'array', 'assets' => 'array', 'navigation' => 'array', 'home' => 'array', 'features' => 'array', 'published_at' => 'datetime'];
    }
}
