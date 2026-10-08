<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\SerialNumber\Dto;

/**
 * One entry in a serial's location audit trail.
 *
 * @package ksfraser\FrontAccounting\SerialNumber\Dto
 * @since 1.0.0
 */
class SerialMoveDto
{
    /** @var int Row id; 0 until inserted. */
    public $id = 0;

    /** @var string */
    public $serialNo = '';

    /** @var string|null Origin FA location. */
    public $fromLocCode = null;

    /** @var string|null Destination FA location. */
    public $toLocCode = null;

    /** @var int|null Origin shelf. */
    public $fromShelfId = null;

    /** @var int|null Destination shelf. */
    public $toShelfId = null;

    /** @var string Why the unit moved (receipt, transfer, return, write-off...). */
    public $reason = '';

    /** @var string|null FA user id. */
    public $movedBy = null;

    /** @var string 'Y-m-d H:i:s'. */
    public $movedAt = '';

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'            => $this->id,
            'serial_no'     => $this->serialNo,
            'from_loc_code' => $this->fromLocCode,
            'to_loc_code'   => $this->toLocCode,
            'from_shelf_id' => $this->fromShelfId,
            'to_shelf_id'   => $this->toShelfId,
            'reason'        => $this->reason,
            'moved_by'      => $this->movedBy,
            'moved_at'      => $this->movedAt,
        );
    }
}