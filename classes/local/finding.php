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
        return array_map(function ($item) use ($slides) {
            return isset($slides[$item])
                ? get_string('itemonslide', 'local_submissioncheck', (object) ['name' => $item, 'slide' => $slides[$item]])
                : $item;
        }, $this->items);
    }

    /**
     * Short form for the staff results table.
     *
     * @return string
     */
    public function get_summary(): string {
        return get_string('findingshort_' . $this->code, 'local_submissioncheck', (object) ($this->localised_params() + [
            'items' => implode(', ', $this->items),
        ]));
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
