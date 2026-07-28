<?php

    class GuestbookAdminController extends z_controller {

        public function action_stats(Request $req, Response $res) {
            // Bare name "GuestbookStats" resolves the model one directory deep
            // inside the module root (recursive lookup).
            return $res->render("guestbook/admin", [
                "count" => $req->getModel("GuestbookStats")->countEntries(),
            ]);
        }

    }

?>
