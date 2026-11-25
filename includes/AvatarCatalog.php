<?php

/**
 * DiceBear 10.x Avatar Whitelist Catalog & Strict Validator (Friendly-Only, No Facial Hair).
 *
 * Enforces strict friendly-only validation for avatar style, random seed, and per-style
 * option keys/values on write, while safely migrating/stripping any legacy blocked or
 * facial hair values on read for existing users.
 */

const AVATAR_MAX_OPTIONS_JSON_BYTES = 1024; // 1 KB hard limit

const AVATAR_ALLOWED_STYLES = [
    'open-peeps',
    'micah',
    'personas',
];

const AVATAR_BACKGROUND_COLORS = [
    'fff4dc',
    'dce6ff',
    '9db8ff',
    'ffc93c',
    'ffd6a5',
    'ffb8d0',
    'c9b8ff',
    'b6e3f4',
    'c0aede',
    'd1d4f9',
    'ffd5dc',
    'ffdfbf',
    'ffffff',
    'transparent',
];

/**
 * Returns strict per-style option specification (zero facial hair options allowed).
 */
function avatar_get_style_option_rules(string $style): array {
    switch ($style) {
        case 'open-peeps':
            return [
                'skinColor' => [
                    'type' => 'color',
                    'values' => ['ffdbb4', 'edb98a', 'd08b5b', 'ae5d29', '694d3d'],
                ],
                'headVariant' => [
                    'type' => 'enum',
                    'values' => [
                        'afro', 'bangs', 'bangs2', 'bantuKnots', 'bear', 'bun', 'bun2', 'buns',
                        'cornrows', 'cornrows2', 'dreads1', 'dreads2', 'flatTop', 'flatTopLong',
                        'grayBun', 'grayMedium', 'grayShort', 'hatBeanie', 'hatHip', 'hijab',
                        'long', 'longAfro', 'longBangs', 'longCurly', 'medium1', 'medium2',
                        'medium3', 'mediumBangs', 'mediumBangs2', 'mediumBangs3', 'mediumStraight',
                        'mohawk', 'mohawk2', 'noHair1', 'noHair2', 'noHair3', 'pomp',
                        'shaved1', 'shaved2', 'shaved3', 'short1', 'short2', 'short3',
                        'short4', 'short5', 'turban', 'twists', 'twists2',
                    ],
                ],
                'headProbability' => ['type' => 'probability'],
                'headContrastColor' => [
                    'type' => 'color',
                    'values' => [
                        '2c1b18', 'e8e1e1', 'ecdcbf', 'd6b370', 'f59797',
                        'b58143', 'a55728', '724133', '4a312c', 'c93305',
                    ],
                ],
                'expressionVariant' => [
                    'type' => 'enum',
                    'values' => [
                        'smile', 'smileBig', 'smileLOL', 'smileTeethGap', 'calm', 'cute',
                        'cheeky', 'lovingGrin1', 'lovingGrin2', 'eatingHappy', 'driven',
                        'explaining', 'awe',
                    ],
                ],
                'expressionProbability' => ['type' => 'probability'],
                'clothingColor' => [
                    'type' => 'color',
                    'values' => ['8fa7df', '78e185', 'ffcf77', 'e279c7', 'e78276', '9ddadb', 'fdea6b'],
                ],
                'accessoriesVariant' => [
                    'type' => 'enum',
                    'values' => [
                        'glasses', 'glasses2', 'glasses3', 'glasses4', 'glasses5',
                        'sunglasses', 'sunglasses2',
                    ],
                ],
                'accessoriesProbability' => ['type' => 'probability'],
                'backgroundColor' => [
                    'type' => 'color',
                    'values' => AVATAR_BACKGROUND_COLORS,
                ],
            ];

        case 'micah':
            $micahAccentColors = [
                'f9c9b6', 'd2eff3', '000000', 'e0ddff', 'f4d150', 'ac6651',
                '9287ff', 'ffeba4', 'fc909f', 'ffedef', '6bd9e9', '77311d', 'ffffff',
            ];
            return [
                'baseColor' => [
                    'type' => 'color',
                    'values' => ['f9c9b6', 'ac6651', '77311d'],
                ],
                'hairVariant' => [
                    'type' => 'enum',
                    'values' => ['dannyPhantom', 'dougFunny', 'fonze', 'full', 'mrClean', 'mrT', 'pixie', 'turban'],
                ],
                'hairProbability' => ['type' => 'probability'],
                'hairColor' => [
                    'type' => 'color',
                    'values' => $micahAccentColors,
                ],
                'eyesVariant' => [
                    'type' => 'enum',
                    'values' => ['eyes', 'round', 'smiling', 'eyesShadow', 'smilingShadow'],
                ],
                'eyesProbability' => ['type' => 'probability'],
                'eyebrowsVariant' => [
                    'type' => 'enum',
                    'values' => ['up', 'eyelashesUp'],
                ],
                'eyebrowsProbability' => ['type' => 'probability'],
                'mouthVariant' => [
                    'type' => 'enum',
                    'values' => ['laughing', 'smile', 'smirk', 'pucker'],
                ],
                'mouthProbability' => ['type' => 'probability'],
                'noseVariant' => [
                    'type' => 'enum',
                    'values' => ['curve', 'pointed', 'tound'],
                ],
                'noseProbability' => ['type' => 'probability'],
                'earsVariant' => [
                    'type' => 'enum',
                    'values' => ['attached', 'detached'],
                ],
                'earsProbability' => ['type' => 'probability'],
                'clothesVariant' => [
                    'type' => 'enum',
                    'values' => ['collared', 'crew', 'open'],
                ],
                'clothesProbability' => ['type' => 'probability'],
                'shirtColor' => [
                    'type' => 'color',
                    'values' => $micahAccentColors,
                ],
                'glassesVariant' => [
                    'type' => 'enum',
                    'values' => ['round', 'square'],
                ],
                'glassesProbability' => ['type' => 'probability'],
                'glassesColor' => [
                    'type' => 'color',
                    'values' => $micahAccentColors,
                ],
                'earringsVariant' => [
                    'type' => 'enum',
                    'values' => ['hoop', 'stud'],
                ],
                'earringsProbability' => ['type' => 'probability'],
                'earringColor' => [
                    'type' => 'color',
                    'values' => $micahAccentColors,
                ],
                'eyeShadowColor' => [
                    'type' => 'color',
                    'values' => ['d2eff3', 'e0ddff', 'ffeba4', 'ffedef', 'ffffff'],
                ],
                'backgroundColor' => [
                    'type' => 'color',
                    'values' => AVATAR_BACKGROUND_COLORS,
                ],
            ];

        case 'personas':
            $personasHairColors = ['362c47', 'f29c65', '6c4545', 'f27d65', 'dee1f5', 'e16381', 'e15c66'];
            return [
                'skinColor' => [
                    'type' => 'color',
                    'values' => ['eeb4a4', 'e7a391', 'e5a07e', 'd78774', 'b16a5b', '92594b', '623d36'],
                ],
                'hairVariant' => [
                    'type' => 'enum',
                    'values' => [
                        'bald', 'balding', 'beanie', 'bobBangs', 'bobCut', 'bunUndercut',
                        'buzzcut', 'cap', 'curly', 'curlyBun', 'curlyHighTop', 'extraLong',
                        'fade', 'long', 'mohawk', 'pigtails', 'shortCombover',
                        'shortComboverChops', 'sideShave', 'straightBun',
                    ],
                ],
                'hairProbability' => ['type' => 'probability'],
                'hairColor' => [
                    'type' => 'color',
                    'values' => $personasHairColors,
                ],
                'eyesVariant' => [
                    'type' => 'enum',
                    'values' => ['happy', 'open', 'glasses', 'sunglasses', 'wink'],
                ],
                'eyesProbability' => ['type' => 'probability'],
                'clothesVariant' => [
                    'type' => 'enum',
                    'values' => ['checkered', 'rounded', 'small', 'squared'],
                ],
                'clothesProbability' => ['type' => 'probability'],
                'clothingColor' => [
                    'type' => 'color',
                    'values' => ['456dff', '6dbb58', 'f55d81', '7555ca', 'e24553', '54d7c7', 'f3b63a'],
                ],
                'mouthVariant' => [
                    'type' => 'enum',
                    'values' => ['bigSmile', 'smile', 'lips', 'smirk'],
                ],
                'mouthProbability' => ['type' => 'probability'],
                'noseVariant' => [
                    'type' => 'enum',
                    'values' => ['mediumRound', 'smallRound', 'wrinkles'],
                ],
                'noseProbability' => ['type' => 'probability'],
                'backgroundColor' => [
                    'type' => 'color',
                    'values' => AVATAR_BACKGROUND_COLORS,
                ],
            ];

        default:
            return [];
    }
}

/**
 * Validates and normalizes an incoming avatar payload on SAVE.
 * Rejects disallowed styles, seeds, option keys/values (including any facial hair
 * option like facialHair/facialHairVariant/facialHairProbability/facialHairColor,
 * masks, eyepatch, rage, sleep, etc.), and > 1 KB option JSON.
 */
function avatar_validate_payload($input): array {
    if (!is_array($input)) {
        return ['ok' => false, 'error' => 'Invalid avatar payload format.'];
    }

    $allowedTopKeys = ['style', 'seed', 'options'];
    foreach ($input as $k => $_) {
        if (!in_array((string)$k, $allowedTopKeys, true)) {
            return ['ok' => false, 'error' => "Unsupported field '{$k}' in avatar payload."];
        }
    }

    $style = $input['style'] ?? null;
    if (!is_string($style) || !in_array($style, AVATAR_ALLOWED_STYLES, true)) {
        return [
            'ok' => false,
            'error' => 'Invalid avatar style. Allowed values are: ' . implode(', ', AVATAR_ALLOWED_STYLES) . '.'
        ];
    }

    $seed = $input['seed'] ?? null;
    if (!is_string($seed) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seed)) {
        return [
            'ok' => false,
            'error' => 'Invalid avatar seed. Must be 1-64 characters matching [A-Za-z0-9_-].'
        ];
    }

    $rawOptions = $input['options'] ?? [];
    if ($rawOptions === null) {
        $rawOptions = [];
    }
    if (!is_array($rawOptions)) {
        return ['ok' => false, 'error' => 'Avatar options must be a JSON object.'];
    }

    $rawEncoded = empty($rawOptions) ? '{}' : json_encode($rawOptions, JSON_UNESCAPED_SLASHES);
    if ($rawEncoded === false || strlen($rawEncoded) > AVATAR_MAX_OPTIONS_JSON_BYTES) {
        return [
            'ok' => false,
            'error' => 'Avatar options JSON exceeds the 1 KB (1024-byte) size limit.'
        ];
    }

    $rules = avatar_get_style_option_rules($style);
    $normalized = [];

    foreach ($rawOptions as $key => $val) {
        if (!is_string($key) || !array_key_exists($key, $rules)) {
            $safeKey = is_scalar($key) ? (string)$key : 'invalid';
            if (is_string($key) && str_starts_with($key, 'facialHair')) {
                return [
                    'ok' => false,
                    'error' => "Facial hair option '{$safeKey}' is not allowed."
                ];
            }
            return [
                'ok' => false,
                'error' => "Unsupported option '{$safeKey}' for style '{$style}'."
            ];
        }

        $rule = $rules[$key];
        $type = $rule['type'];

        if ($type === 'probability') {
            if (is_int($val)) {
                $prob = $val;
            } elseif (is_string($val) && preg_match('/^(0|[1-9][0-9]{0,2})$/', $val)) {
                $prob = (int)$val;
            } else {
                return [
                    'ok' => false,
                    'error' => "Invalid probability for '{$key}'. Expected an integer between 0 and 100."
                ];
            }

            if ($prob < 0 || $prob > 100) {
                return [
                    'ok' => false,
                    'error' => "Invalid value for '{$key}'. Probability must be between 0 and 100."
                ];
            }
            $normalized[$key] = (string)$prob;
        } elseif ($type === 'color') {
            if (!is_string($val)) {
                return ['ok' => false, 'error' => "Invalid color type for '{$key}'."];
            }
            $colorLower = strtolower(trim($val));
            if ($colorLower !== 'transparent' && !preg_match('/^[0-9a-f]{6}$/', $colorLower)) {
                return [
                    'ok' => false,
                    'error' => "Invalid color format for '{$key}'. Expected a 6-character hex value without '#'."
                ];
            }
            if (!in_array($colorLower, $rule['values'], true)) {
                return [
                    'ok' => false,
                    'error' => "Color '{$val}' is not in the allowed palette for '{$key}'."
                ];
            }
            $normalized[$key] = $colorLower;
        } elseif ($type === 'enum') {
            if (!is_string($val) || !in_array($val, $rule['values'], true)) {
                return [
                    'ok' => false,
                    'error' => "Invalid value '{$val}' for '{$key}' in style '{$style}'."
                ];
            }
            $normalized[$key] = $val;
        }
    }

    ksort($normalized);
    $optionsJson = empty($normalized) ? '{}' : json_encode($normalized, JSON_UNESCAPED_SLASHES);
    if ($optionsJson === false || strlen($optionsJson) > AVATAR_MAX_OPTIONS_JSON_BYTES) {
        return [
            'ok' => false,
            'error' => 'Avatar options JSON exceeds the 1 KB (1024-byte) size limit.'
        ];
    }

    return [
        'ok' => true,
        'style' => $style,
        'seed' => $seed,
        'options' => $normalized,
        'options_json' => $optionsJson,
    ];
}

/**
 * Sanitizes stored legacy options on READ so existing users with previously saved
 * blocked or facial hair values gracefully see clean friendly avatars without crashing.
 */
function avatar_sanitize_legacy_options(string $style, array $decodedOptions): array {
    $rules = avatar_get_style_option_rules($style);
    $sanitized = [];

    foreach ($decodedOptions as $key => $val) {
        if (!is_string($key)) {
            continue;
        }

        // Strip all facial hair options cleanly on read
        if (str_starts_with($key, 'facialHair')) {
            continue;
        }

        // Remove mask options completely
        if ($style === 'open-peeps' && ($key === 'maskVariant' || $key === 'maskProbability')) {
            continue;
        }

        // Replace blocked open-peeps accessories (eyepatch) -> hide accessory cleanly
        if ($style === 'open-peeps' && $key === 'accessoriesVariant' && is_string($val)) {
            if (!in_array($val, $rules['accessoriesVariant']['values'], true)) {
                $sanitized['accessoriesProbability'] = '0';
                unset($sanitized['accessoriesVariant']);
                continue;
            }
        }

        // Fallback blocked expressions / mouths / eyebrows / eyes to nearest allowed friendly value
        if ($key === 'expressionVariant' && $style === 'open-peeps') {
            $sanitized[$key] = (is_string($val) && in_array($val, $rules[$key]['values'], true)) ? $val : 'smile';
            continue;
        }
        if ($key === 'mouthVariant' && isset($rules['mouthVariant'])) {
            $sanitized[$key] = (is_string($val) && in_array($val, $rules[$key]['values'], true)) ? $val : 'smile';
            continue;
        }
        if ($key === 'eyebrowsVariant' && $style === 'micah') {
            if (is_string($val) && in_array($val, $rules[$key]['values'], true)) {
                $sanitized[$key] = $val;
            } else {
                $sanitized[$key] = ($val === 'eyelashesDown') ? 'eyelashesUp' : 'up';
            }
            continue;
        }
        if ($key === 'eyesVariant' && $style === 'personas') {
            $sanitized[$key] = (is_string($val) && in_array($val, $rules[$key]['values'], true)) ? $val : 'open';
            continue;
        }

        if (!array_key_exists($key, $rules)) {
            continue;
        }

        $rule = $rules[$key];
        if ($rule['type'] === 'probability') {
            if (is_int($val) && $val >= 0 && $val <= 100) {
                $sanitized[$key] = (string)$val;
            } elseif (is_string($val) && preg_match('/^(0|[1-9][0-9]{0,2})$/', $val) && (int)$val <= 100) {
                $sanitized[$key] = (string)((int)$val);
            }
        } elseif ($rule['type'] === 'color' && is_string($val)) {
            $lower = strtolower(trim($val));
            if (in_array($lower, $rule['values'], true)) {
                $sanitized[$key] = $lower;
            }
        } elseif ($rule['type'] === 'enum' && is_string($val)) {
            if (in_array($val, $rule['values'], true)) {
                $sanitized[$key] = $val;
            }
        }
    }

    if ($style === 'open-peeps' && isset($decodedOptions['accessoriesVariant']) && !in_array($decodedOptions['accessoriesVariant'], $rules['accessoriesVariant']['values'], true)) {
        unset($sanitized['accessoriesVariant']);
        $sanitized['accessoriesProbability'] = '0';
    }

    ksort($sanitized);
    return $sanitized;
}

/**
 * Formats stored owner_account avatar columns into the public avatar code object,
 * safely normalizing any legacy blocked or facial hair values cleanly.
 */
function avatar_format_from_row(?array $row): ?array {
    if (!$row) {
        return null;
    }

    $style = $row['avatar_style'] ?? null;
    $seed = $row['avatar_seed'] ?? null;
    $optionsRaw = $row['avatar_options'] ?? null;

    if (!is_string($style) || !in_array($style, AVATAR_ALLOWED_STYLES, true)) {
        return null;
    }
    if (!is_string($seed) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seed)) {
        return null;
    }

    $decodedOptions = [];
    if (is_string($optionsRaw) && trim($optionsRaw) !== '') {
        $parsed = json_decode($optionsRaw, true);
        if (is_array($parsed)) {
            $decodedOptions = $parsed;
        }
    }

    $sanitized = avatar_sanitize_legacy_options($style, $decodedOptions);

    return [
        'style' => $style,
        'seed' => $seed,
        'options' => empty($sanitized) ? new stdClass() : (object)$sanitized,
    ];
}
