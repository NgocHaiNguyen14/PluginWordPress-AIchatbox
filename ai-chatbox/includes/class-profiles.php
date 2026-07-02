<?php

class AICB_Profiles
{
    const ALLOWED_POSITIONS = ['bottom-right', 'bottom-left'];

    public static function get_defaults(): array
    {
        return [
            'path_prefix' => '',
            'is_default' => false,
            'assistant_name' => 'Assistant',
            'avatar_url' => '',
            'system_prompt' => 'You are a helpful customer support assistant.',
            'welcome_message' => 'Hi! How can I help you today?',
            'accent_color' => '#4f46e5',
            'widget_position' => 'bottom-right',
            'quick_replies' => [],
        ];
    }

    public static function sanitize(array $input): array
    {
        $defaults = self::get_defaults();

        $path_prefix = isset($input['path_prefix']) ? trim((string) $input['path_prefix']) : $defaults['path_prefix'];
        $path_prefix = rtrim($path_prefix, '/');

        $is_default = !empty($input['is_default']);

        $assistant_name = isset($input['assistant_name'])
            ? sanitize_text_field($input['assistant_name'])
            : $defaults['assistant_name'];
        if ('' === $assistant_name) {
            $assistant_name = $defaults['assistant_name'];
        }

        $avatar_url = isset($input['avatar_url']) ? trim((string) $input['avatar_url']) : $defaults['avatar_url'];

        $system_prompt = isset($input['system_prompt'])
            ? sanitize_textarea_field($input['system_prompt'])
            : $defaults['system_prompt'];
        if ('' === $system_prompt) {
            $system_prompt = $defaults['system_prompt'];
        }

        $welcome_message = isset($input['welcome_message'])
            ? sanitize_text_field($input['welcome_message'])
            : $defaults['welcome_message'];
        if ('' === $welcome_message) {
            $welcome_message = $defaults['welcome_message'];
        }

        $accent_color = isset($input['accent_color']) ? sanitize_hex_color($input['accent_color']) : '';
        if ('' === $accent_color) {
            $accent_color = $defaults['accent_color'];
        }

        $widget_position = isset($input['widget_position']) ? (string) $input['widget_position'] : $defaults['widget_position'];
        if (!in_array($widget_position, self::ALLOWED_POSITIONS, true)) {
            $widget_position = $defaults['widget_position'];
        }

        $quick_replies = [];
        if (isset($input['quick_replies']) && is_array($input['quick_replies'])) {
            foreach ($input['quick_replies'] as $reply) {
                $label = isset($reply['label']) ? sanitize_text_field($reply['label']) : '';
                $message = isset($reply['message']) ? sanitize_text_field($reply['message']) : '';
                if ('' === $label || '' === $message) {
                    continue;
                }
                $quick_replies[] = ['label' => $label, 'message' => $message];
            }
        }

        return [
            'path_prefix' => $path_prefix,
            'is_default' => $is_default,
            'assistant_name' => $assistant_name,
            'avatar_url' => $avatar_url,
            'system_prompt' => $system_prompt,
            'welcome_message' => $welcome_message,
            'accent_color' => $accent_color,
            'widget_position' => $widget_position,
            'quick_replies' => $quick_replies,
        ];
    }

    public static function path_prefix_conflicts(string $path_prefix, array $other_profiles): bool
    {
        $normalized = rtrim($path_prefix, '/');

        foreach ($other_profiles as $profile) {
            $other = rtrim((string) ($profile['path_prefix'] ?? ''), '/');
            if ('' !== $other && $other === $normalized) {
                return true;
            }
        }

        return false;
    }
}
