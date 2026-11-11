<?php

declare(strict_types=1);

namespace Nucleo\Schema;

defined('ABSPATH') || exit;

use Nucleo\Guide\RuleCodes;

/** One actionable validation problem. $path is a JSON Pointer ("/root/children/0/settings/level"). */
final class ValidationError
{
    /** @param list<mixed> $allowed */
    public function __construct(
        public readonly string $path,
        public readonly string $message,
        public readonly mixed $got = null,
        public readonly array $allowed = [],
        public readonly ?string $fix = null,
        /** codice stabile per gli agenti (es. card_missing); null per gli errori dello schema */
        public readonly ?string $code = null,
    ) {
    }

    /** @return array{path:string,message:string,got?:mixed,allowed?:list<mixed>,fix?:string,code?:string,guide?:string} */
    public function toArray(): array
    {
        $a = ['path' => $this->path, 'message' => $this->message];
        if ($this->got !== null) {
            $a['got'] = $this->got;
        }
        if ($this->allowed !== []) {
            $a['allowed'] = $this->allowed;
        }
        if ($this->fix !== null) {
            $a['fix'] = $this->fix;
        }
        if ($this->code !== null) {
            $a['code'] = $this->code;
            $guide = RuleCodes::forError($this->code);
            if ($guide !== null) {
                $a['guide'] = $guide;
            }
        }
        return $a;
    }
}
