{{--
    Renders a Filament action only when it is visible for the current user.
    Filament's toHtml() does not check visibility for actions rendered by hand
    in Blade, so every manually placed action goes through this component.
    The slot stands in for a hidden action, e.g. a plain badge.
--}}
@props(['action'])

@if ($action->isVisible())
    {{ $action }}
@else
    {{ $slot }}
@endif
