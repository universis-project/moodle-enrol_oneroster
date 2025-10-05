<?php
namespace enrol_oneroster;

use progress_trace;
class json_progress_trace extends progress_trace {

    protected array $messages = [];
    public function __construct() {
        //
    }

    #[\Override]
    public function output(
        string $message,
        int $depth = 0,
    ): void {
        $this->messages []= $message;
    }
    public function get_data(): array {
        return array_filter($this->messages, fn($line) => trim($line) !== '');
    }

}