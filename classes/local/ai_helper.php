<?php

namespace mod_flashcards\local;

use coding_exception;
use moodle_exception;
use Throwable;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates AI focus phrase detection + dictionary lookup + TTS generation.
 */
class ai_helper {
    /** @var openai_client */
    protected $openai;
    /** @var tts_service */
    protected $tts;

    public function __construct(?openai_client $openai = null, ?tts_service $tts = null) {
        $this->openai = $openai ?? new openai_client();
        $this->tts = $tts ?? new tts_service();
    }

    /**
     * Main entry for AJAX handler.
     *
     * @param int $userid
     * @param string $fronttext
     * @param string $clickedword
     * @param array $options ['language' => string, 'voice' => string|null]
     * @return array
     * @throws moodle_exception
     */
    public function process_focus_request(int $userid, string $fronttext, string $clickedword, array $options = []): array {
        if (!$this->openai->is_enabled()) {
            throw new moodle_exception('ai_disabled', 'mod_flashcards');
        }

        $language = trim($options['language'] ?? '') ?: 'no';
        $focus = $this->openai->detect_focus_phrase($fronttext, $clickedword, $language);

        $result = [
            'focusWord' => $focus,
            'focusBaseform' => $focus,
        ];

        if ($dict = orbokene_repository::find($focus)) {
            if (!empty($dict['baseform'])) {
                $result['focusBaseform'] = $dict['baseform'];
            }
            if (!empty($dict['definition'])) {
                $result['definition'] = $dict['definition'];
            }
            if (!empty($dict['translation'])) {
                $result['translation'] = $dict['translation'];
            }
            if (!empty($dict['examples'])) {
                $result['examples'] = $dict['examples'];
            }
            if (!empty($dict['grammar'])) {
                $result['grammar'] = $dict['grammar'];
            }
        }

        $audio = [];
        if ($this->tts->is_enabled()) {
            $voice = $options['voice'] ?? null;
            try {
                $audio['front'] = $this->tts->synthesize($userid, $fronttext, [
                    'voice' => $voice,
                    'label' => 'front',
                ]);
            } catch (Throwable $e) {
                debugging('[flashcards] TTS front_text failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            try {
                $audio['focus'] = $this->tts->synthesize($userid, $focus, [
                    'voice' => $voice,
                    'label' => 'focus',
                ]);
            } catch (Throwable $e) {
                debugging('[flashcards] TTS focus failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if (!empty($audio)) {
            $result['audio'] = $audio;
        }

        return $result;
    }
}
