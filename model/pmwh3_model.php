<?php

class Pmwh3_Model extends \ckvsoft\mvc\Model
{

    private $_table = 'menu';

    public function __construct()
    {
        parent::__construct();
        $this->moduleDb = $this->moduleDb();
    }

    /**
     * Alle Items für Box 0 (General) holen
     */
    public function getBoxItems($box = 0)
    {
        return $this->moduleDb->select(
                        "SELECT * FROM {$this->_table} WHERE box = :box ORDER BY sort",
                        ['box' => $box]
                );
    }

    /**
     * Einzelnes Item nach sort holen
     */
    public function getItemBySort($sort, $box = 0)
    {
        $result = $this->moduleDb->select(
                "SELECT * FROM {$this->_table} WHERE box = :box AND sort = :sort",
                ['box' => $box, 'sort' => $sort]
        );

        return !empty($result) ? $result[0] : false;
    }
}
