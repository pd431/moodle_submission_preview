<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_submissioncheck\local;

/**
 * Something a check found in a file, worth telling the student.
 *
 * @package    local_submissioncheck
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finding {
    /** @var string Language string key suffix: finding_<code>. */
    public $code;

    /** @var array Values for the language string. */
    public $params;

    /** @var string[] Names listed under the message, such as linked files. */
    public $items;

    /**
     * Constructor.
     *
     * @param string $code
     * @param array $params
     * @param string[] $items
     */
    public function __construct(string $code, array $params = [], array $items = []) {
        $this->code = $code;
        $this->params = $params;
        $this->items = array_values($items);
    }

    /**
     * Plain array for storage.
     *
     * @return array
     */
    public function to_array(): array {
        return ['code' => $this->code, 'params' => $this->params, 'items' => $this->items];
    }

    /**
     * Rebuild from storage.
     *
     * @param array $data
     * @return self
     */
    public static function from_array(array $data): self {
        return new self((string) ($data['code'] ?? ''), (array) ($data['params'] ?? []), (array) ($data['items'] ?? []));
    }

    /**
     * The message shown to the student.
     *
     * @return string
     */
    public function get_message(): string {
        $params = $this->localised_params();
        if ($this->code === 'linkedmedia') {
            $params['link'] = (string) get_config('local_submissioncheck', 'helplink');
        }
        return get_string('finding_' . $this->code, 'local_submissioncheck', (object) array_filter($params, 'is_scalar'));
    }

    /**
     * The listed items as shown to the student, with the slide each is on when the check found it.
     *
     * @return string[]
     */
    public function item_labels(): array {
        $slides = (array) ($this->params['slides'] ?? []);
        $hosts = (array) ($this->params['hosts'] ?? []);
        return array_map(function ($item) use ($slides, $hosts) {
            $a = (object) ['name' => $item, 'slide' => $slides[$item] ?? null, 'host' => $hosts[$item] ?? null];
            if ($a->host !== null) {
                return get_string($a->slide !== null ? 'itemcloudslide' : 'itemcloud', 'local_submissioncheck', $a);
            }
            return $a->slide !== null ? get_string('itemonslide', 'local_submissioncheck', $a) : $item;
        }, $this->items);
    }

    /**
     * The broken items this finding lists, as the student's red box shows them: name, short reason and place. Null
     * when the finding is about the whole file rather than items in it.
     *
     * @return array|null [['name' => string, 'reason' => string, 'place' => string|null, 'target' => string|null], ...]
     */
    public function broken_items(): ?array {
        if (!in_array($this->code, ['linkedmedia', 'cloudmedia', 'brokenlink'], true)) {
            return null;
        }
        $slides = (array) ($this->params['slides'] ?? []);
        $hosts = (array) ($this->params['hosts'] ?? []);
        $targets = (array) ($this->params['targets'] ?? []);
        $items = [];
        foreach ($this->items as $name) {
            $host = $hosts[$name] ?? null;
            if ($this->code === 'linkedmedia') {
                $reason = get_string('brokenreason_local', 'local_submissioncheck');
            } else if ($this->code === 'cloudmedia') {
                $reason = get_string('brokenreason_cloud', 'local_submissioncheck', $host);
            } else {
                $reason = $host === null ? get_string('brokenreason_locallink', 'local_submissioncheck')
                    : get_string('brokenreason_signinlink', 'local_submissioncheck', $host);
            }
            $items[] = [
                'name' => $name,
                'reason' => $reason,
                'place' => isset($slides[$name]) ? get_string('brokenplace_slide', 'local_submissioncheck', $slides[$name]) : null,
                'target' => $targets[$name] ?? null,
            ];
        }
        return $items;
    }

    /**
     * Short form for the staff results table.
     *
     * @return string
     */
    public function get_summary(): string {
        return get_string('findingshort_' . $this->code, 'local_submissioncheck', (object) (array_filter(
            $this->localised_params(),
            'is_scalar'
        ) + ['items' => implode(', ', $this->item_labels())]));
    }

    /**
     * Parameters with stored string identifiers (kinds of file) turned into words.
     *
     * Findings are cached, so they hold identifiers rather than text in one language.
     *
     * @return array
     */
    protected function localised_params(): array {
        $params = $this->params;
        foreach (['kind', 'expected', 'actual'] as $key) {
            if (isset($params[$key]) && get_string_manager()->string_exists($params[$key], 'local_submissioncheck')) {
                $params[$key] = get_string($params[$key], 'local_submissioncheck');
            }
        }
        return $params;
    }
}
