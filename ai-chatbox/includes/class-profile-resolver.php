<?php

class AICB_Profile_Resolver
{
    public static function resolve(string $request_path, array $profiles): array
    {
        if (empty($profiles)) {
            return AICB_Profiles::get_defaults();
        }

        $normalized_path = rtrim($request_path, '/');

        $best_match = null;
        $best_length = -1;
        $default_profile = null;

        foreach ($profiles as $profile) {
            if (!empty($profile['is_default'])) {
                $default_profile = $profile;
            }

            $prefix = rtrim((string) ($profile['path_prefix'] ?? ''), '/');
            if ('' === $prefix) {
                continue;
            }

            $matches = ($normalized_path === $prefix || 0 === strpos($normalized_path, $prefix . '/'));
            if ($matches && strlen($prefix) > $best_length) {
                $best_match = $profile;
                $best_length = strlen($prefix);
            }
        }

        if (null !== $best_match) {
            return $best_match;
        }

        return $default_profile ?? AICB_Profiles::get_defaults();
    }
}
