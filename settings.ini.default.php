<?php

/**
 * settings.ini.default.php — the template for the settings of math.qu.lt.
 *
 * Copy it to a real settings file and fill in the secrets:
 *
 *     install -m 640 -o root -g www-data settings.ini.default.php /etc/qu_lt/settings.ini.php
 *     # ... then edit /etc/qu_lt/settings.ini.php and put in the API key
 *
 * **The real file is `settings.ini.php`, right next to index.php** (it is
 * `.gitignore`d). Copy it there:
 *
 *     cp settings.ini.default.php settings.ini.php
 *     chown root:www-data settings.ini.php && chmod 640 settings.ini.php
 *
 * That is only safe because the nginx vhost denies it:
 *
 *     location ~* \.ini\.php$ { deny all; }      # curl … → 403
 *
 * nginx *executes* only index.php but serves every other file below
 * /raid1/sites/qu_lt/math as plain text (`curl https://math.qu.lt/lib/Tts.php`
 * really returns the source - that is how the API key used to leak), so without
 * that deny rule a settings file in here hands the key to anyone who guesses
 * the name. This template holds no keys, which is why it may live here.
 *
 * The loader (tts_settings_file() in lib/Tts.php) looks for the file in this
 * order:
 *
 *   1. $MATH_TTS_SETTINGS              (a path — tests, other hosts)
 *   2. <docroot>/settings.ini.php      (the real file, denied by the vhost)
 *   3. /etc/qu_lt/settings.ini.php     (outside the docroot, for hosts that
 *                                       cannot deny it in the vhost)
 *
 * The legacy JSON file /etc/qu_lt/math-tts.json is still read (it keeps old
 * installs working), and environment variables (ELEVENLABS_*, MATH_TTS_SECRET,
 * MATH_TTS_CONFIG) override every file. A key that is missing or empty simply
 * keeps the value from the layer below, so `api_key` is the only thing a working
 * install has to fill in.
 *
 * Every value that changes the sound of a sentence (voice, model, language,
 * output format, voice settings, speed) is part of the mp3 cache key: change one
 * of them and the sentences are generated again (that costs credits).
 */
return [
    // https://elevenlabs.io/app/settings/api-keys — empty = voice feature off.
    'api_key' => '',

    // Empty = remember the first voice of the account in cache/tts/voice.json.
    'voice_id' => 'tnSpp4vdxKPjI9w0GnoV',

    // v3 is the model that speaks Lithuanian (eleven_multilingual_v2 does not).
    'model_id' => 'eleven_v3',
    'language_code' => 'lt',
    'output_format' => 'mp3_44100_128',

    // eleven_v3 only accepts stability (0.0 = creative, 0.5 = natural,
    // 1.0 = robust). Add similarity_boost / style / use_speaker_boost when
    // switching to another model.
    'voice_settings' => ['stability' => 0.5],

    // 0.9 = a little slower, for a child who has to follow the sentence.
    'speed' => '0.9',

    'base_url' => 'https://api.elevenlabs.io/v1',
    'timeout' => 30,

    // Signing key for the per-phrase /api/tts tokens. Empty = derive one from
    // the API key, so a fresh install is protected with no extra config. Set it
    // to a random string to rotate the tokens without touching the API key.
    'client_secret' => '',
];
