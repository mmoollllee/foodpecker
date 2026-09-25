{{--
    Renders a Filament action only when it is visible for the current user.
    Filament's toHtml() does not check visibility for actions rendered by hand
    in Blade, so every manually placed action goes through this component.
--}}
@props(['action'])

@if ($action->isVisible())
    {{ $action }}
@endif
