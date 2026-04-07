<?php

function renderDropdown(
    string $name,
    array  $options,
    string $selected    = '',
    string $placeholder = 'Select...',
    bool   $required    = false,
    string $extraClass  = ''
): void {

    // Build options for Alpine
    $alpineOptions = [];
    foreach ($options as $val => $label) {
        $alpineOptions[] = [
            'value' => (string)$val,
            'text'  => (string)$label
        ];
    }

    // Alpine state (pure JSON)
    $state = json_encode([
        'open'    => false,
        'value'   => (string)$selected,
        'options' => $alpineOptions
    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

    $req = $required ? ' required' : '';
    $ec  = $extraClass ? ' ' . $extraClass : '';
    $ph  = htmlspecialchars($placeholder, ENT_QUOTES);

    // Use full width when w-full class is passed, else auto-size to content
    if (strpos($extraClass, 'w-full') !== false) {
        $widthStyle = 'style="width:100%"';
    } else {
        $longest = $placeholder;
        foreach ($options as $label) {
            if (mb_strlen($label) > mb_strlen($longest)) $longest = $label;
        }
        $widthStyle = 'style="width:' . (mb_strlen($longest) * 0.6 + 2) . 'rem"';
    }

    echo '
    <div x-data=\'' . $state . '\' class="relative' . $ec . '" ' . $widthStyle . '>

        <!-- Trigger — border-only focus, no ring -->
        <button type="button"
            @click="open=!open"
            @keydown.escape="open=false"
            class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">

            <span
                x-text="options.find(o => o.value === value)?.text || \'' . $ph . '\'"
                :class="value ? \'text-black\' : \'text-slate-400\'">
            </span>

            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform"
                :class="open ? \'rotate-180\' : \'\'"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <!-- Dropdown panel — fixed positioning to avoid clipping -->
        <div x-show="open"
            @click.outside="open=false"
            style="display:none"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="fixed z-50 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden"
            x-init="$watch(\'open\', function(v) {
                if (v) {
                    var r = $el.previousElementSibling.getBoundingClientRect();
                    $el.style.top   = (r.bottom + 4) + \'px\';
                    $el.style.left  = r.left + \'px\';
                    $el.style.width = r.width + \'px\';
                }
            })">

            <ul class="max-h-56 overflow-y-auto py-1">

                <template x-for="item in options" :key="item.value">
                    <li>
                        <button type="button"
                            @click="value=item.value; open=false"
                            class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                            :class="value===item.value
                                ? \'bg-indigo-50 text-indigo-700 font-medium\'
                                : \'text-black hover:bg-slate-50\'">

                            <span x-text="item.text"></span>
                        </button>
                    </li>
                </template>

            </ul>
        </div>

        <!-- Hidden select -->
        <select name="' . htmlspecialchars($name, ENT_QUOTES) . '"
            x-model="value"
            ' . $req . '
            class="absolute opacity-0 pointer-events-none w-0 h-0 top-0 left-0"
            tabindex="-1"
            aria-hidden="true">

            <option value="" disabled>' . $ph . '</option>';

    foreach ($options as $val => $label) {
        $sel = ($selected === (string)$val) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string)$val, ENT_QUOTES) . '"' . $sel . '>'
            . htmlspecialchars((string)$label, ENT_QUOTES) .
        '</option>';
    }

    echo '
        </select>
    </div>';
}
