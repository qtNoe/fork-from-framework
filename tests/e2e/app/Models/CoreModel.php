<?php

    class CoreModel extends z_model {

        public function getData() {
            return "Test Model Call";
        }

        public function insertData($testName) {
            $sql = "INSERT INTO `model_test_insert` (`value`)
                    VALUES (?)";
            return $this->exec($sql, "s", $testName);
        }

        public function getModelTestsInsert() {
            $sql = "SELECT * 
                    FROM `model_test_insert`";
            return $this->exec($sql)->resultToArray();
        }

        public function getModelTestsLine() {
            $sql = "SELECT *
                    FROM `model_test_select`";
            return $this->exec($sql)->resultToLine();
        }

        public function getModelCount() {
            $sql = "SELECT *
                    FROM `model_test_select`";
            return $this->exec($sql)->countResults();
        }

        public function getModelTestsArray() {
            $sql = "SELECT *
                    FROM `model_test_select`";
            return $this->exec($sql)->resultToArray();
        }

        public function getModelLastId() {
            // The generated id depends on the cluster's auto-increment offset,
            // so the contract is asserted as identity: getInsertId() must name
            // the row this INSERT created (read back as the table's maximum,
            // which is this row: the suite runs no concurrent inserts).
            $sql = "INSERT INTO `model_test_lastid` (`value`)
                    VALUES ('LastId')";
            $insertId = (int) $this->exec($sql)->getInsertId();

            $readBack = (int) $this->exec("SELECT MAX(`id`) AS m FROM `model_test_lastid`")
                ->resultToArray()[0]["m"];

            if($insertId > 0 && $insertId === $readBack) return "lastid-consistent";
            return "lastid-mismatch:$insertId:$readBack";
        }

        public function insertLargeFile() {
            $assetId = model("z_file")->add(
                "bigint-test",
                "test/virtual",
                "virtual",
                "bin",
                FILE_SIZE_100GB,
            );

            $sql = "SELECT `size` FROM `z_file` WHERE `id` = ?";
            $row = $this->exec($sql, "i", $assetId)->resultToLine();

            return [
                "input" => (string) FILE_SIZE_100GB,
                "stored" => (string) $row["size"],
            ];
        }
    }

?>