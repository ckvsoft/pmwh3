<?php

// modules/pmwh3/model/filtering_model.php

use ckvsoft\mvc\Model;
use pmwh3\Utils\CustomerUtil;
use pmwh3\Utils\FilteringManager;

class Filtering_Model extends Model
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * All policy rows visible to the caller, newest scope ordering:
     * global first, then domains, then single mailboxes.
     */
    public function listAll(): array
    {
        return $this->moduleDb()->select(
                "SELECT * FROM pmwh3_filtering
                 ORDER BY FIELD(scope_type,'global','domain','email'), scope",
                []
        );
    }

    public function getRow(int $id): ?array
    {
        return $this->moduleDb()->selectOne(
                "SELECT * FROM pmwh3_filtering WHERE id = :id",
                ['id' => $id]
        );
    }

    public function save(
            ?int $id,
            string $scopeType,
            string $scope,
            ?float $tagThreshold,
            ?float $killThreshold
    ): int {
        $scope = trim(strtolower($scope));
        if ($scope === '' || !in_array($scopeTypeFinal = $this->scopeType($scope), ['email', 'domain', 'global'], true)) {
            return 0;
        }
        $data = [
            'scope_type'     => $scopeTypeFinal,
            'scope'          => $scope,
            'tag_threshold'  => $tagThreshold,
            'kill_threshold' => $killThreshold,
            'updated_by'     => (string) (\ckvsoft\Session::getNs('pmwh3', 'customer_name') ?? ''),
        ];
        $db = $this->moduleDb();
        if ($id > 0 && $this->getRow($id)) {
            $db->update('pmwh3_filtering', $data, 'id = :i', ['i' => $id]);
            return $id;
        }
        $db->insert('pmwh3_filtering', $data);
        return (int) $db->id();
    }

    private function scopeType(string $scope): string
    {
        if ($scope === '@.') return 'global';
        if (str_starts_with($scope, '@')) return 'domain';
        return 'email';
    }

    public function delete(int $id): bool
    {
        $db = $this->moduleDb();
        $row = $db->selectOne("SELECT scope FROM pmwh3_filtering WHERE id = :id", ['id' => $id]);
        if (!$row || ($row['scope'] ?? '') === '@.') {
            return false; // global row is structural, keep it
        }
        $db->delete('pmwh3_filtering', 'id = :id', ['id' => $id]);
        return true;
    }

    // ========== WBList ===================================================

    public function listWb(?string $listType = null): array
    {
        $sql = "SELECT * FROM pmwh3_wblist";
        $bind = [];
        if ($listType !== null) {
            $sql .= " WHERE list_type = :t";
            $bind['t'] = ($listType === 'B') ? 'B' : 'W';
        }
        $sql .= " ORDER BY FIELD(scope_type,'global','domain','email'), scope, address";
        return $this->moduleDb()->select($sql, $bind);
    }

    public function getWbRow(int $id): ?array
    {
        return $this->moduleDb()->selectOne(
                "SELECT * FROM pmwh3_wblist WHERE id = :id", ['id' => $id]
        );
    }

    public function saveWb(
            ?int $id,
            string $scope,
            string $listType,
            string $address,
            string $comment = ''
    ): bool {
        $scope = trim(strtolower($scope));
        $address = trim($address);
        if ($scope === '' || $address === '') {
            return false;
        }
        $data = [
            'scope_type' => $this->scopeType($scope),
            'scope'      => $scope,
            'list_type'  => ($listType === 'B') ? 'B' : 'W',
            'address'    => $address,
            'comment'    => mb_substr($comment, 0, 255),
            'created_by' => (string) (\ckvsoft\Session::getNs('pmwh3', 'customer_name') ?? ''),
        ];
        $db = $this->moduleDb();
        if ($id > 0 && $this->getWbRow($id)) {
            $db->update('pmwh3_wblist', $data, 'id = :id', ['id' => $id]);
            return true;
        }
        $db->insert('pmwh3_wblist', $data);
        return (int) $db->id() > 0;
    }

    public function deleteWb(int $id): bool
    {
        $this->moduleDb()->delete('pmwh3_wblist', 'id = :id', ['id' => $id]);
        return true;
    }
}
