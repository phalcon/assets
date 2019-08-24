
/**
 * This file is part of the Phalcon Framework.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

var PrettyExceptions = {

    /**
     * Initialize the pretty print and highlights lines on files
     */
    initialize: function()
    {

        prettyPrint();

        var prettyprint = $(".prettyprint");
        for (var i = 0; i < prettyprint.length; i++) {
            $($(prettyprint[i]).attr("class").split(" ")).each(
                function(k, v) {
                    if (v.match("^highlight")) {
                        var lis = $("li", prettyprint[i]);
                        var position = Math.abs(v.split(":")[2] - v.split(":")[1]);
                        var li = lis[position];
                        $(li).addClass("highlight");
                        for (var j = position - 10; j < lis.length; j++) {
                            if (j >= 0) {
                                $(prettyprint[i]).scrollTo(lis[j]);
                                break;
                            }
                        }
                    }
                }
            );
        }

        $('#tabs').tabs();
    }

};

$(document).ready(function() {
    PrettyExceptions.initialize();
});
