<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validierung
    |--------------------------------------------------------------------------
    |
    | Diese Texte werden von Laravel bei Formular- und Eingabevalidierungen
    | verwendet. Platzhalter wie :attribute, :min oder :max müssen erhalten bleiben.
    |
    */

    'accepted' => 'Das Feld :attribute muss akzeptiert werden.',
    'accepted_if' => 'Das Feld :attribute muss akzeptiert werden, wenn :other den Wert :value hat.',
    'active_url' => 'Das Feld :attribute muss eine gültige URL enthalten.',
    'after' => 'Das Feld :attribute muss ein Datum nach :date enthalten.',
    'after_or_equal' => 'Das Feld :attribute muss ein Datum nach oder am :date enthalten.',
    'alpha' => 'Das Feld :attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => 'Das Feld :attribute darf nur Buchstaben, Zahlen, Bindestriche und Unterstriche enthalten.',
    'alpha_num' => 'Das Feld :attribute darf nur Buchstaben und Zahlen enthalten.',
    'any_of' => 'Das Feld :attribute ist ungültig.',
    'array' => 'Das Feld :attribute muss eine Liste enthalten.',
    'ascii' => 'Das Feld :attribute darf nur einbytefähige alphanumerische Zeichen und Symbole enthalten.',
    'before' => 'Das Feld :attribute muss ein Datum vor :date enthalten.',
    'before_or_equal' => 'Das Feld :attribute muss ein Datum vor oder am :date enthalten.',
    'between' => [
        'array' => 'Das Feld :attribute muss zwischen :min und :max Einträge enthalten.',
        'file' => 'Die Datei :attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss zwischen :min und :max liegen.',
        'string' => 'Das Feld :attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => 'Das Feld :attribute muss wahr oder falsch sein.',
    'can' => 'Das Feld :attribute enthält einen nicht erlaubten Wert.',
    'confirmed' => 'Die Bestätigung für :attribute stimmt nicht überein.',
    'contains' => 'Im Feld :attribute fehlt ein erforderlicher Wert.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => 'Das Feld :attribute muss ein gültiges Datum enthalten.',
    'date_equals' => 'Das Feld :attribute muss dem Datum :date entsprechen.',
    'date_format' => 'Das Feld :attribute muss dem Format :format entsprechen.',
    'decimal' => 'Das Feld :attribute muss :decimal Dezimalstellen haben.',
    'declined' => 'Das Feld :attribute muss abgelehnt werden.',
    'declined_if' => 'Das Feld :attribute muss abgelehnt werden, wenn :other den Wert :value hat.',
    'different' => 'Die Felder :attribute und :other müssen unterschiedlich sein.',
    'digits' => 'Das Feld :attribute muss genau :digits Ziffern enthalten.',
    'digits_between' => 'Das Feld :attribute muss zwischen :min und :max Ziffern enthalten.',
    'dimensions' => 'Das Bild :attribute hat ungültige Abmessungen.',
    'distinct' => 'Das Feld :attribute enthält einen doppelten Wert.',
    'doesnt_end_with' => 'Das Feld :attribute darf nicht mit einem der folgenden Werte enden: :values.',
    'doesnt_start_with' => 'Das Feld :attribute darf nicht mit einem der folgenden Werte beginnen: :values.',
    'email' => 'Das Feld :attribute muss eine gültige E-Mail-Adresse enthalten.',
    'ends_with' => 'Das Feld :attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Die ausgewählte Option für :attribute ist ungültig.',
    'exists' => 'Die ausgewählte Option für :attribute ist ungültig.',
    'extensions' => 'Das Feld :attribute muss eine Datei mit einer der folgenden Erweiterungen enthalten: :values.',
    'file' => 'Das Feld :attribute muss eine Datei enthalten.',
    'filled' => 'Das Feld :attribute muss ausgefüllt sein.',
    'gt' => [
        'array' => 'Das Feld :attribute muss mehr als :value Einträge enthalten.',
        'file' => 'Die Datei :attribute muss größer als :value Kilobyte sein.',
        'numeric' => 'Das Feld :attribute muss größer als :value sein.',
        'string' => 'Das Feld :attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => 'Das Feld :attribute muss mindestens :value Einträge enthalten.',
        'file' => 'Die Datei :attribute muss mindestens :value Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss mindestens :value sein.',
        'string' => 'Das Feld :attribute muss mindestens :value Zeichen lang sein.',
    ],
    'hex_color' => 'Das Feld :attribute muss eine gültige Hex-Farbe enthalten.',
    'image' => 'Das Feld :attribute muss ein Bild enthalten.',
    'in' => 'Die ausgewählte Option für :attribute ist ungültig.',
    'in_array' => 'Das Feld :attribute muss in :other vorhanden sein.',
    'in_array_keys' => 'Das Feld :attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => 'Das Feld :attribute muss eine ganze Zahl enthalten.',
    'ip' => 'Das Feld :attribute muss eine gültige IP-Adresse enthalten.',
    'ipv4' => 'Das Feld :attribute muss eine gültige IPv4-Adresse enthalten.',
    'ipv6' => 'Das Feld :attribute muss eine gültige IPv6-Adresse enthalten.',
    'json' => 'Das Feld :attribute muss gültiges JSON enthalten.',
    'list' => 'Das Feld :attribute muss eine Liste sein.',
    'lowercase' => 'Das Feld :attribute muss in Kleinbuchstaben geschrieben sein.',
    'lt' => [
        'array' => 'Das Feld :attribute muss weniger als :value Einträge enthalten.',
        'file' => 'Die Datei :attribute muss kleiner als :value Kilobyte sein.',
        'numeric' => 'Das Feld :attribute muss kleiner als :value sein.',
        'string' => 'Das Feld :attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => 'Das Feld :attribute darf höchstens :value Einträge enthalten.',
        'file' => 'Die Datei :attribute darf höchstens :value Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute darf höchstens :value sein.',
        'string' => 'Das Feld :attribute darf höchstens :value Zeichen lang sein.',
    ],
    'mac_address' => 'Das Feld :attribute muss eine gültige MAC-Adresse enthalten.',
    'max' => [
        'array' => 'Das Feld :attribute darf höchstens :max Einträge enthalten.',
        'file' => 'Die Datei :attribute darf höchstens :max Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute darf höchstens :max sein.',
        'string' => 'Das Feld :attribute darf höchstens :max Zeichen lang sein.',
    ],
    'max_digits' => 'Das Feld :attribute darf höchstens :max Ziffern enthalten.',
    'mimes' => 'Das Feld :attribute muss eine Datei dieses Typs enthalten: :values.',
    'mimetypes' => 'Das Feld :attribute muss eine Datei dieses Typs enthalten: :values.',
    'min' => [
        'array' => 'Das Feld :attribute muss mindestens :min Einträge enthalten.',
        'file' => 'Die Datei :attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss mindestens :min sein.',
        'string' => 'Das Feld :attribute muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => 'Das Feld :attribute muss mindestens :min Ziffern enthalten.',
    'missing' => 'Das Feld :attribute darf nicht vorhanden sein.',
    'missing_if' => 'Das Feld :attribute darf nicht vorhanden sein, wenn :other den Wert :value hat.',
    'missing_unless' => 'Das Feld :attribute darf nur vorhanden sein, wenn :other den Wert :value hat.',
    'missing_with' => 'Das Feld :attribute darf nicht vorhanden sein, wenn :values vorhanden ist.',
    'missing_with_all' => 'Das Feld :attribute darf nicht vorhanden sein, wenn :values vorhanden sind.',
    'multiple_of' => 'Das Feld :attribute muss ein Vielfaches von :value sein.',
    'not_in' => 'Die ausgewählte Option für :attribute ist ungültig.',
    'not_regex' => 'Das Format von :attribute ist ungültig.',
    'numeric' => 'Das Feld :attribute muss eine Zahl enthalten.',
    'password' => [
        'letters' => 'Das Feld :attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => 'Das Feld :attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => 'Das Feld :attribute muss mindestens eine Zahl enthalten.',
        'symbols' => 'Das Feld :attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Dieses :attribute wurde in einem Datenleck gefunden. Bitte wähle ein anderes :attribute.',
    ],
    'present' => 'Das Feld :attribute muss vorhanden sein.',
    'present_if' => 'Das Feld :attribute muss vorhanden sein, wenn :other den Wert :value hat.',
    'present_unless' => 'Das Feld :attribute muss vorhanden sein, sofern :other nicht den Wert :value hat.',
    'present_with' => 'Das Feld :attribute muss vorhanden sein, wenn :values vorhanden ist.',
    'present_with_all' => 'Das Feld :attribute muss vorhanden sein, wenn :values vorhanden sind.',
    'prohibited' => 'Das Feld :attribute ist nicht erlaubt.',
    'prohibited_if' => 'Das Feld :attribute ist nicht erlaubt, wenn :other den Wert :value hat.',
    'prohibited_if_accepted' => 'Das Feld :attribute ist nicht erlaubt, wenn :other akzeptiert wurde.',
    'prohibited_if_declined' => 'Das Feld :attribute ist nicht erlaubt, wenn :other abgelehnt wurde.',
    'prohibited_unless' => 'Das Feld :attribute ist nur erlaubt, wenn :other einen der folgenden Werte hat: :values.',
    'prohibits' => 'Das Feld :attribute verhindert, dass :other vorhanden sein darf.',
    'regex' => 'Das Format von :attribute ist ungültig.',
    'required' => 'Das Feld :attribute ist erforderlich.',
    'required_array_keys' => 'Das Feld :attribute muss Einträge für folgende Schlüssel enthalten: :values.',
    'required_if' => 'Das Feld :attribute ist erforderlich, wenn :other den Wert :value hat.',
    'required_if_accepted' => 'Das Feld :attribute ist erforderlich, wenn :other akzeptiert wurde.',
    'required_if_declined' => 'Das Feld :attribute ist erforderlich, wenn :other abgelehnt wurde.',
    'required_unless' => 'Das Feld :attribute ist erforderlich, außer :other hat einen der folgenden Werte: :values.',
    'required_with' => 'Das Feld :attribute ist erforderlich, wenn :values vorhanden ist.',
    'required_with_all' => 'Das Feld :attribute ist erforderlich, wenn :values vorhanden sind.',
    'required_without' => 'Das Feld :attribute ist erforderlich, wenn :values nicht vorhanden ist.',
    'required_without_all' => 'Das Feld :attribute ist erforderlich, wenn keiner der folgenden Werte vorhanden ist: :values.',
    'same' => 'Das Feld :attribute muss mit :other übereinstimmen.',
    'size' => [
        'array' => 'Das Feld :attribute muss genau :size Einträge enthalten.',
        'file' => 'Die Datei :attribute muss :size Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss :size sein.',
        'string' => 'Das Feld :attribute muss :size Zeichen lang sein.',
    ],
    'starts_with' => 'Das Feld :attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => 'Das Feld :attribute muss Text enthalten.',
    'timezone' => 'Das Feld :attribute muss eine gültige Zeitzone enthalten.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'uppercase' => 'Das Feld :attribute muss in Großbuchstaben geschrieben sein.',
    'url' => 'Das Feld :attribute muss eine gültige URL enthalten.',
    'ulid' => 'Das Feld :attribute muss eine gültige ULID enthalten.',
    'uuid' => 'Das Feld :attribute muss eine gültige UUID enthalten.',

    /*
    |--------------------------------------------------------------------------
    | Eigene Validierungstexte
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'Benutzerdefinierte Meldung',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Eigene Attributnamen
    |--------------------------------------------------------------------------
    */

    'attributes' => [],

];
