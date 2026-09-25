@include('filament.partials.activities', [
    'activities' => $this->getRecord()->activities()->with('user')->limit(50)->get(),
])
