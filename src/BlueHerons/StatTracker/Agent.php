<?php
namespace BlueHerons\StatTracker;

use StdClass;
use DateTime;
use Exception;

use BlueHerons\StatTracker\StatTracker;

class Agent {

    public $name;
    public $token;
    public $faction;
    public $level;
    public $stats;

    const TOKEN_WEB = "WebApp";

    /**
     * Returns the registered Agent for the given email address. If no agent is found, a generic
     * Agent object is returned.
     *
     * @param string $email_address
     *
     * @return string Agent object
     */
    public static function lookupAgentName($email_address) {
        $stmt = StatTracker::db()->prepare("SELECT agent, faction FROM Agent WHERE email = ?;");
        $stmt->execute(array($email_address));
        extract($stmt->fetch());
        $stmt->closeCursor();

        if (empty($agent)) {
            return new Agent();
        }
        else {
            $agent = new Agent($agent);
            $agent->faction = $faction;

            $stmt = StatTracker::db()->prepare("SELECT token FROM Tokens WHERE agent = ? AND name = ? AND revoked = ?;");
            $stmt->execute(array($agent->name, Agent::TOKEN_WEB, 0));
            extract($stmt->fetch());
            $stmt->closeCursor();

            if ($token !== null) {
                $agent = new Agent($agent->name, $token);
                $agent->faction = $faction;
            }

            return $agent;
        }
    }

    public static function lookupAgentByToken($token) {
        $stmt = StatTracker::db()->prepare("SELECT a.agent, a.faction FROM Agent a JOIN Tokens t ON t.agent = a.agent WHERE t.token = ? AND t.revoked = ?;");
        $stmt->execute(array($token, 0));
        extract($stmt->fetch());
        $stmt->closeCursor();

        if (empty($agent)) {
            return new Agent();
        }
        else {
            $stmt = StatTracker::db()->prepare("UPDATE Tokens SET last_used = NOW() WHERE token = ?;");
            $stmt->execute(array($token));
            $stmt->closeCursor();

            $agent = new Agent($agent, $token);
            $agent->faction = $faction;
            return $agent;
        }
    }

    /**
     * Constructs a new Agent object for the given agent name. This object will include all information
     * publicly visible from the "Agent Profile" screen in Ingress: Agent name, AP, and badges earned.
     *
     * @param string $agent the name of the agent. This name will be searched for in the database. If
     * it is not found, an exception will be thrown.
     *
     * @return Agent object with public stats populated.
     *
     * @throws Exception if agent name is not found.
     */
    public function __construct($agent = "Agent", $token = null) {
        if (!is_string($agent)) {
            throw new Exception("Agent name must be a string");
        }

        $this->name = $agent;
        $this->token = $token;

        if ($this->isValid()) {
            $this->getLevel();
            $this->hasSubmitted();
            $this->getStat('ap');
            $this->getUpdateTimestamp();
            $this->getTokens();
        }
    }

    /**
     * Determines if a valid name has been set for this agent.
     *
     * @return boolean true if agent is valid, false otherwise
     */
    public function isValid() {
        return $this->name != "Agent" && !empty($this->token);
    }

    /**
     * Generates a breakdown of AP earned by stat
     *
     * @param int $days_back Days before the more recent submission that should be considered for the Breakdown
     *
     * @return string Object AP Breakdown object
     */
    public function getAPBreakdown($days_back = 0) {
        $stmt = StatTracker::db()->prepare("CALL GetAPBreakdown(?, ?);");
        $stmt->execute(array($this->name, $days_back));
        $stmt->closeCursor();

        $stmt = StatTracker::db()->query("SELECT * FROM APBreakdown ORDER BY grouping, sequence ASC;");

        $data = array();
        $colors = array();

        // TODO: Numbers only!
        while ($row = $stmt->fetch()) {
            $data[] = array($row['name'], $row['ap_gained']);
            if ($row['grouping'] == 1) {
                $color =$this->faction == "R" ? ENL_GREEN : RES_BLUE;
            }
            else if ($row['grouping'] == 3) {
                $color = $this->faction == "R" ? RES_BLUE : ENL_GREEN;
            }
            else {
                $color = "#999";
            }
            $colors[] = $color;
        }
        $stmt->closeCursor();

        return array("data" => $data, "slice_colors" => $colors);
    }

    public function getToken() {
        return $this->token;
    }

    /**
     * Gets the access tokens associated with this agent
     *
     * @param $refresh Refresh the cached list of access tokens
     */
    public function getTokens($refresh = false) {
        if (!isset($this->tokens) || $refresh) {
            $stmt = StatTracker::db()->prepare("SELECT name FROM Tokens WHERE agent = ? AND revoked = ?;");
            $stmt->execute(array($this->name, 0));
            $tokens = array();

            while ($row = $stmt->fetch()) {
                extract($row);
                $tokens[] = $name;
            }
            $this->tokens = $tokens;
        }

        return $this->tokens;
    }

    /**
     * Creates a new access token. The token is returned once from this method, it cannot be retrieved again.
     *
     * @return the token if a new one was created, false if not
     */
    public function createToken($name) {
        if (!in_array($name, $this->getTokens())) {
            $stmt = StatTracker::db()->prepare("INSERT INTO Tokens (agent, name, token) VALUES(?, UCASE(?), SHA2(CONCAT(?, ?, UUID()), 256));");
            $stmt->execute(array($this->name, $name, $this->name, $name));

            // A token is return only when it is created
            $stmt = StatTracker::db()->prepare("SELECT token FROM Tokens WHERE agent = ? AND name = UCASE(?) AND revoked = ?");
            $stmt->execute(array($this->name, $name, 0));
            extract($stmt->fetch());
            return $token;
        }

        return false;
    }

    /**
     * Revokes the named token. If the web is revoked, a new one will be generated automatically
     */
    public function revokeToken($name) {
        if (in_array($name, $this->getTokens())) {
            $stmt = StatTracker::db()->prepare("UPDATE Tokens SET revoked = ?, name = CONCAT(name, '-', UNIX_TIMESTAMP(NOW())) WHERE agent = ? and name = UCASE(?)");
            $stmt->execute(array(1, $this->name, $name));

            // Web token is special. If it was revoked, another one needs to be created
            if (strtoupper($name) == Agent::TOKEN_WEB) {
                $this->getTokens(true);
                $this->createToken(strtoupper($name));
            }

            return true;
        }

        return false;
    }

    /**
     * Generates JSON formatted data for use in a line graph.
     *
     * @param string $stat the stat to generate the data for
     *
     * @return string Object Graph Data object
     */
    public function getGraphData($stat) {
        $stmt = StatTracker::db()->prepare("CALL GetGraphForStat(?, ?);");
        $stmt->execute(array($this->name, $stat));

        $stmt = StatTracker::db()->query("SELECT * FROM GraphDataForStat;");

        $data = array();
        while ($row = $stmt->fetch()) {
            if (sizeof($data) == 0) {
                foreach (array_keys($row) as $key) {
                    $series = new StdClass();
                    $series->name = $key;
                    $series->data = array();
                    $data[] = $series;
                }
            }

            $i = 0;
            foreach (array_values($row) as $value) {
                $data[$i]->data[] = $value;

                $i++;
            }
        }
        $stmt->closeCursor();

        $response = new StdClass();
        $response->data = $data;
        $response->prediction = $this->getPrediction($stat); // TODO: move elsewhere

        return $response;
    }

    /**
     * Gets the current level for the Agent. Considers AP and badges.
     *
     * @returns int current Agent level
     */
    public function getLevel($date = "latest") {
        if (!isset($this->level)) {

            if ($date == "latest") {
                $date = date("Y-m-d");
            }

            $stmt = StatTracker::db()->prepare("CALL GetLevel(?, ?);");
            $stmt->execute(array($this->name, $date));
            $stmt->closeCursor();

            $stmt = StatTracker::db()->query("SELECT level FROM _Level;");
            extract($stmt->fetch());
            $stmt->closeCursor();

            $this->level = $level;
        }

        return $this->level;
    }

    public function getTrend($stat, $when) {
        $start = "";
        $end = "";

        switch ($when) {
            case "last-week":
                $start = date("Y-m-d", strtotime("last monday", strtotime("6 days ago")));
                $end = date("Y-m-d", strtotime("next sunday", strtotime("8 days ago")));
                break;
            case "this-week":
            case "weekly":
            default:
                $start = date("Y-m-d", strtotime("last monday", strtotime("tomorrow")));
                $end = date("Y-m-d", strtotime("next sunday", strtotime("yesterday")));
                break;
        }

        $stmt = StatTracker::db()->prepare("CALL GetDailyTrend(?, ?, ?, ?);");
        $stmt->execute(array($this->name, $stat, $start, $end));
        $stmt->closeCursor();

        $stmt = StatTracker::db()->query("SELECT * FROM DailyTrend");

        $data = array();
        while ($row = $stmt->fetch()) {
            $data["dates"][] = $row["date"];
            $data["target"][] = $row["target"];
            $data["value"][] = $row["value"];
        }
        $stmt->closeCursor();

        return $data;
    }

    /**
     * Determines if the Agent has submitted to Stat Tracker
     */
    public function hasSubmitted($refresh = false) {
        if (!isset($this->has_submitted) || $refresh) {
            $stmt = StatTracker::db()->prepare("SELECT count(stat) > 0 AS result FROM Data WHERE stat = 'ap' AND agent = ?;");
            $stmt->execute(array($this->name));
            extract($stmt->fetch());
            $stmt->closeCursor();

            $this->has_submitted = $result > 0;
        }

        return $this->has_submitted;
    }

    /**
     * Gets the timestamp for which the last update was made for the agent. If $date is provided, the timestamp will
     * be the update for that day
     */
    public function getUpdateTimestamp($date = "latest", $refresh = false) {
        if (!isset($this->update_time) || $this->update_time == null || $refresh) {
            $stmt = null;
            if ($date == "latest" || new DateTime() < new DateTime($date)) {
                $stmt = StatTracker::db()->prepare("SELECT UNIX_TIMESTAMP(MAX(updated)) `updated` FROM Data WHERE agent = ?");
                $stmt->execute(array($this->name));
            }
            else {
                $stmt = StatTracker::db()->prepare("SELECT UNIX_TIMESTAMP(MAX(updated)) `updated` FROM Data WHERE agent = ? AND date = ?;");
                $stmt->execute(array($this->name, $date));
            }

            extract($stmt->fetch());
            $stmt->closeCursor();

            $this->update_time = $updated;
        }

        return $this->update_time;
    }

    /**
     * Gets the latest date that a submission was made for.
     *
     * @param boolean $refresh whether or not to refresh the cached values
     *
     * @return string date of latest submission
     */
    public function getLatestSubmissionDate($refresh = false) {
        $ts = $this->getUpdateTimestamp("latest", $refresh);
        $stmt = StatTracker::db()->prepare("SELECT date FROM Data WHERE agent = ? and updated = FROM_UNIXTIME(?)");
        $stmt->execute(array($this->name, $ts));

        extract($stmt->fetch());
        $stmt->closeCursor();

        return $date;
    }

    /**
     * Gets the values of all stats.
     *
     * @param string|date $when "latest" to get the latest stats submitted by the agent, or a date in "yyyy-mm-dd"
     *                    format to retrieve  stats on that date
     * @param boolean $refresh whether or not to refresh the cached values
     *
     * @return array values for stats
     */
    public function getStats($when = "latest", $refresh = true) {
        if (!is_array($this->stats) || $refresh) {

            if ($when == "latest" || new DateTime() < new DateTime($when)) {
                $when = $this->getLatestSubmissionDate($refresh);
            }

            $stmt = StatTracker::db()->prepare("SELECT stat, value FROM Data WHERE agent = ? AND date = ? ORDER BY stat ASC;");
            $stmt->execute(array($this->name, $when));

            if (!is_array($this->stats) || $refresh) {
                $this->stats = array();
                $this->stats['ap'] = 0;
            }

            while ($row = $stmt->fetch()) {
                extract($row);
                $this->stats[$stat] = $value;
            }

            $stmt->closeCursor();
        }

        return $this->stats;
    }

    /**
     * Gets the value of the specified stat.
     *
     * @param string|object If string, the stat's database key. If object, a Stat object for the class
     * #param boolean $refresh whether or not to refresh the cached value
     *
     * @return the value for the stat
     */
    public function getStat($stat, $when = "latest", $refresh = false) {
        if (is_object($stat)) {
            $stat = $stat->stat;
        }

        if (!isset($this->stats[$stat]) || $refresh) {
            $ts = $this->getUpdateTimestamp($when, $refresh);
            if ($when == "latest" || new DateTime() < new DateTime($when)) {
                $when = $this->getLatestSubmissionDate($refresh);
            }

            $stmt = StatTracker::db()->prepare("SELECT value FROM Data WHERE stat = ? AND agent = ? AND (date = ? OR updated = FROM_UNIXTIME(?)) ORDER BY date DESC LIMIT 1;");
            $r = $stmt->execute(array($stat, $this->name, $when, $ts));
            extract($stmt->fetch());
            $stmt->closeCursor();

            if (!is_array($this->stats)) {
                $this->stats = array();
            }

            $this->stats[$stat] = !is_numeric($value) ? 0 : $value;
        }

        return $this->stats[$stat];
    }

    /**
     * Gets an array of badges for the current player. array index is the badge name, and the array value
     * is the level of the current badge
     *
     * @param boolean $refresh Whether or not to refresh the cached values
     *
     * @return array the array of current badges the Agent has earned
     */
    public function getBadges($date = "today", $refresh = false) {
        if (!is_array($this->badges) || $refresh) {
            $stmt = StatTracker::db()->prepare("CALL GetBadges(?, ?);");

            if ($date == "today") {
                $today = true;
                $date = date("Y-m-d");
            }

            $stmt->execute(array($this->name, $date));
            $stmt->closeCursor();

            $stmt = StatTracker::db()->query("SELECT * FROM _Badges;");

            if ($today && $stmt->rowCount() == 0) {
                $this->getBadges(date("Y-m-d", $this->getUpdateTimestamp("latest", $refresh)), true);
            }

            if (!is_array($this->badges)) {
                $this->badges = array();
            }

            while ($row = $stmt->fetch()) {
                extract($row);
                $badge = str_replace(" ", "_", $badge);
                $badge = strtolower($badge);

                $this->badges[$badge] = strtolower($level);
            }

            $stmt->closeCursor();
        }

        return $this->badges;
    }

    /**
     * Gets the prediction for a stat. If the stat has a badge associated with it, this will also
     * retrieve the badge name, current level, next level, and percentage complete to attain the next
     * badge level.
     *
     * @param string $stat Stat to retrieve prediction for
     *
     * @return Object prediciton object
     */
    public function getPrediction($stat) {
        $prediction = new StdClass();
        $stmt = StatTracker::db()->prepare("CALL GetBadgePrediction(?, ?);");
        $stmt->execute(array($this->name, $stat));

        $stmt = StatTracker::db()->query("SELECT * FROM BadgePrediction");
        $row = $stmt->fetch();

        $prediction->stat = $row['stat'];
        $prediction->name = $row['name'];
        $prediction->unit = $row['unit'];
        $prediction->badge = $row['badge'];
        $prediction->current = $row['current'];
        $prediction->next = $row['next'];
        $prediction->rate = $row['rate'];
        $prediction->progress = $row['progress'];
        $prediction->days_remaining = $row['days'];
        $prediction->target_date = date("Y-m-d", strtotime("+" . round($row['days']) . " days"));

        $local_fmt = ($row['days'] >= 365) ? "F j, Y" : "F j";
        $prediction->target_date_local = date($local_fmt, strtotime("+" . round($row['days']) . " days"));

        if ($stat !== "level") {
            $prediction->amount_remaining = $row['remaining'];
        }
        else {
            $prediction->silver_remaining = $row['silver_remaining'];
            $prediction->gold_remaining = $row['gold_remaining'];
            $prediction->platinum_remaining = $row['platinum_remaining'];
            $prediction->onyx_remaining = $row['onyx_remaining'];
        }

        return $prediction;
    }

    /**
     * Gets the ratios of stats for the given agent.
     *
     * @return array top level entries are a ratio "pair", with a sub array containing keys stat1, stat2, and ratio
     */
    public function getRatios() {
        if (!is_array($this->ratios)) {
            $stmt = StatTracker::db()->prepare("CALL GetRatiosForAgent(?);");
            $stmt->execute(array($this->name));
            $stmt->closeCursor();

            $stmt = StatTracker::db()->query("SELECT * FROM RatiosForAgent WHERE badge_1 IS NOT NULL AND badge_2 IS NOT NULL;");

            $this->ratios = array();

            while ($row = $stmt->fetch()) {
                extract($row);
                $badge = str_replace(" ", "_", $badge);
                $badge = strtolower($badge);

                $this->ratio[] = array(
                    "stat1" => array(
                        "stat" => $stat_1,
                        "badge" => strtolower(str_replace(" ", "_", $badge_1)),
                        "level" => strtolower($badge_1_level),
                        "name" => $stat_1_name,
                        "nickname" => $stat_1_nickname,
                        "unit" => $stat_1_unit,
                    ),
                    "stat2" => array(
                        "stat" => $stat_2,
                        "badge" => strtolower(str_replace(" ", "_", $badge_2)),
                        "level" => strtolower($badge_2_level),
                        "name" => $stat_2_name,
                        "nickname" => $stat_2_nickname,
                        "unit" => $stat_2_unit
                    ),
                    "ratio" => $ratio,
                    "step" => $factor
                );
            }
            $stmt->closeCursor();
        }

        return $this->ratio;
    }

    /**
     * Gets the next X badges for the agent, ordered by least time remaining
     *
     * @param int $limit number of badges to return, default 3
     *
     * @return array of badges
     */
    public function getUpcomingBadges($limit = 4) {
        if (!is_array($this->upcoming_badges)) {
            $stmt = StatTracker::db()->prepare("CALL GetUpcomingBadges(?);");
            $stmt->execute(array($this->name));
            $stmt->closeCursor();

            // sprintf still used intentionally
            $stmt = StatTracker::db()->query(sprintf("SELECT * FROM UpcomingBadges ORDER BY days_remaining ASC LIMIT %d;", $limit));

            if (!is_array($this->upcoming_badges)) {
                $this->upcoming_badges = array();
            }

            while ($row = $stmt->fetch()) {
                extract($row);

                $this->upcoming_badges[] = array(
                    "name" => $badge,
                    "level" => ucfirst($next),
                    "progress" => $progress,
                    "days_remaining" => $days_remaining,
                    "target_date" => date("Y-m-d", strtotime("+" . round($days_remaining) . " days")),
                    "target_date_local" => date("F j", strtotime("+" . round($days_remaining) . " days"))
                );
            }
        }

        return $this->upcoming_badges;
    }

    /**
     * Updates the agent's stats.
     *
     * @param array $data associative array where key is stat and value is the value for the stat.
     */
    public function updateStats($data, $allow_lower) {
        // Get lowest submission date
        $stmt = StatTracker::db()->prepare("SELECT COALESCE(MIN(date), CAST(NOW() AS Date)) `min_date` FROM Data WHERE agent = ?");

        try {
            $stmt->execute(array($this->name));
            extract($stmt->fetch());

            $ts = date("Y-m-d 00:00:00");
            $dt = $data['date'] == null ? date("Y-m-d") : $data['date'];
            $select_stmt = StatTracker::db()->prepare("SELECT value `current_value` FROM Data WHERE agent = ? AND date = ? AND stat = ?");
            $insert_stmt = StatTracker::db()->prepare("INSERT INTO Data (agent, date, timepoint, stat, value) VALUES (?, ?, DATEDIFF(?, ?) + 1, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value);");

            StatTracker::db()->beginTransaction();

            foreach ($data as $stat => $value) {
                if ($stat == "date") continue;
                $value = filter_var($data[$stat], FILTER_SANITIZE_NUMBER_INT);
                $value = !is_numeric($value) ? 0 : $value;

                if ($allow_lower) {
                    $insert_stmt->execute(array($this->name, $dt, $dt, $min_date, $stat, $value));
                }
                else {
                    $select_stmt->execute(array($this->name, $dt, $stat));
                    extract($select_stmt->fetch());
                    $select_stmt->closeCursor();

                    if ($current_value <= $value) {
                        $insert_stmt->execute(array($this->name, $dt, $dt, $min_date, $stat, $value));
                    }
                    else {
                        StatTracker::db()->rollback();
                        return sprintf("Stats cannot be updated. %s is lower than %s for %s.", number_format($value), number_format($current_value), StatTracker::getStats()[$stat]->name);
                    }
                }
            }

            StatTracker::db()->commit();
            return true;
        }
        catch (Exception $e) {
            throw $e;
        }
        finally {
            $select_stmt->closeCursor();
            $insert_stmt->closeCursor();
        }
    }
}
?>
