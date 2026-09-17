<?php

// modules/pmwh3/model/tools_model.php

use ckvsoft\mvc\Model;

class Tools_Model extends Model
{

    // ========== News (announcements) =====================================

    public function listNews(int $limit = 0): array
    {
        $sql = "SELECT * FROM pmwh3_news ORDER BY datetime DESC";
        if ($limit > 0) {
            $sql .= " LIMIT " . (int) $limit;
        }
        return $this->moduleDb()->select($sql, []);
    }

    /**
     * Active (visible) news for the login ticker, newest first,
     * capped by MAX_NEWS.
     */
    public function listActiveNews(int $limit = 10): array
    {
        $limit = $limit > 0 ? (int) $limit : 10;
        return $this->moduleDb()->select(
                "SELECT * FROM pmwh3_news
                  WHERE active = 'Y'
                  ORDER BY datetime DESC
                  LIMIT $limit",
                []
        );
    }

    public function getNewsRow(int $id): ?array
    {
        return $this->moduleDb()->selectOne(
                "SELECT * FROM pmwh3_news WHERE id = :i", ['i' => $id]
        );
    }

    public function saveNews(?int $id, string $text, bool $active = false): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $data = [
            // datetime NOT NULL with legacy zero-date default
            // ('1000-01-01') on migrated installs -- set it explicitly.
            'datetime' => date('Y-m-d H:i:s'),
            'news'     => $text,
            'active'   => $active ? 'Y' : 'N',
            'author'   => (string) (\ckvsoft\Session::getNs('pmwh3', 'customer_name') ?? ''),
            'authorid' => (int) (\ckvsoft\Session::getNs('pmwh3', 'customer_id') ?? 0),
        ];
        $db = $this->moduleDb();
        if ($id > 0 && $this->getNewsRow($id)) {
            $db->update('pmwh3_news', $data, 'id = :i', ['i' => $id]);
            return $id;
        }
        $db->insert('pmwh3_news', $data);
        return (int) $db->id();
    }

    public function deleteNews(int $id): bool
    {
        $this->moduleDb()->delete('pmwh3_news', 'id = :i', ['i' => $id]);
        return true;
    }

    public function toggleNews(int $id): bool
    {
        $db = $this->moduleDb();
        $row = $this->getNewsRow($id);
        if ($row) {
            $db->update('pmwh3_news',
                    ['active' => ($row['active'] ?? 'N') === 'Y' ? 'N' : 'Y'],
                    'id = :i', ['i' => $id]);
        }
        return true;
    }

    // ========== Applications (external app shortcuts) ====================

    public function listApplications(): array
    {
        return $this->moduleDb()->select(
                "SELECT * FROM pmwh3_applications ORDER BY sort, name", []
        );
    }

    public function getApplicationRow(int $id): ?array
    {
        return $this->moduleDb()->selectOne(
                "SELECT * FROM pmwh3_applications WHERE id = :i", ['i' => $id]
        );
    }

    public function saveApplication(?int $id, string $name, string $link, int $sort): int
    {
        $name = trim($name);
        $link = trim($link);
        if ($name === '' || $link === '') {
            return 0;
        }
        if (!preg_match('~^https?://~i', $link)) {
            $link = 'https://' . $link;
        }
        $data = [
            'name' => mb_substr($name, 0, 100),
            'link' => mb_substr($link, 0, 255),
            'sort' => $sort,
        ];
        $db = $this->moduleDb();
        if ($id > 0 && $this->getApplicationRow($id)) {
            $db->update('pmwh3_applications', $data, 'id = :i', ['i' => $id]);
            return $id;
        }
        $db->insert('pmwh3_applications', $data);
        return (int) $db->id();
    }

    public function deleteApplication(int $id): bool
    {
        $this->moduleDb()->delete('pmwh3_applications', 'id = :i', ['i' => $id]);
        return true;
    }
}
