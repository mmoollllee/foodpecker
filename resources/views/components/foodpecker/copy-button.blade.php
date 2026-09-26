{{--
    Copies a value such as an IBAN with one click — with a prompt to copy
    by hand where the clipboard isn't available or allowed.
--}}
@props(['value', 'label' => 'kopieren'])

<button
    type="button"
    x-data="{ copied: false }"
    x-on:click="
        const value = @js($value);
        const copyByHand = () => window.prompt('Kopieren (Strg/Cmd + C):', value);
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(() => { copied = true; setTimeout(() => copied = false, 2000) }, copyByHand);
        } else {
            copyByHand();
        }
    "
    {{ $attributes->class('text-xs font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400') }}
>
    <span x-show="! copied">{{ $label }}</span>
    <span x-show="copied" x-cloak>✓ kopiert</span>
</button>
