<?php

namespace App\Http\Controllers;

use App\Models\Round;
use App\Services\Rounds\PackingList;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class PackingListController extends Controller
{
    /**
     * A page to print for handing out the goods: what everybody gets from
     * the final order. Members see all carts and proposals anyway; for
     * everybody else the round doesn't exist.
     */
    public function show(Round $round, PackingList $packingList): View
    {
        abort_unless(Gate::allows('view', $round), 404);
        abort_if($round->chosen_proposal_id === null, 404);

        return view('packing-list', [
            'round' => $round->loadMissing(['group', 'lead']),
            'people' => $packingList->peopleFor($round),
            'products' => $packingList->productsFor($round),
        ]);
    }
}
