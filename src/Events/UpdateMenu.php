<?php

namespace OptimistDigital\MenuBuilder\Events;

use App\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdateMenu
{
    use Dispatchable, SerializesModels;


    private $id = '';

    /**
     * Create a new event instance.
     *
     * @param integer $id
     */
    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }
}
