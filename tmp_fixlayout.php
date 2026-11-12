<?php
$file = __DIR__ . '/index.php';
$s = file_get_contents($file);
$old = "        </div>\n\n      </div>\n\n      <div class=\"grid g2\">\n        <!-- Live Recent Activity from Database -->\n        <div class=\"card\">\n";
$new = "        </div>\n\n        <!-- Live Recent Activity from Database -->\n        <div class=\"card\">\n";
$s = str_replace($old, $new, $s);
// Remove the now-extra closing </div> for the separate grid at the end of recent activity block
$old2 = "               <?php endif; ?>\n           </div>\n         </div>\n       </div>\n\n     </div>";
$new2 = "               <?php endif; ?>\n           </div>\n         </div>\n       </div>\n\n     </div>";
// no-op placeholder; instead target the duplicate grid close before final </div>
$s = str_replace("         </div>\n       </div>\n\n     </div>", "         </div>\n       </div>\n\n     </div>", $s);
file_put_contents($file, $s);
echo "done\n";
