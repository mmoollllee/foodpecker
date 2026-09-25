{{--
    One labelled fact in a phase panel.
--}}
@props(['label'])

<div {{ $attributes }}>
    <dt class="text-gray-500">{{ $label }}</dt>
    <dd class="font-medium">{{ $slot }}</dd>
</div>
