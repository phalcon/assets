<?php

/**
 * The color names of CSS (npm package color-name 2.1.1, MIT License)
 * and its system colors (npm package mdn-data 2.37.2, CC0 License), in
 * lowercase. transparent and currentcolor are not here, because common.css can
 * use them. Do not change this file by hand: run _tokens/color-names.mjs.
 */

declare(strict_types=1);

const COLOR_NAMES = [
    'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black', 'blanchedalmond',
    'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral', 'cornflowerblue',
    'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray', 'darkgreen', 'darkgrey',
    'darkkhaki', 'darkmagenta', 'darkolivegreen', 'darkorange', 'darkorchid', 'darkred', 'darksalmon',
    'darkseagreen', 'darkslateblue', 'darkslategray', 'darkslategrey', 'darkturquoise', 'darkviolet', 'deeppink',
    'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick', 'floralwhite', 'forestgreen', 'fuchsia',
    'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green', 'greenyellow', 'grey', 'honeydew', 'hotpink',
    'indianred', 'indigo', 'ivory', 'khaki', 'lavender', 'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue',
    'lightcoral', 'lightcyan', 'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink',
    'lightsalmon', 'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue',
    'lightyellow', 'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine', 'mediumblue',
    'mediumorchid', 'mediumpurple', 'mediumseagreen', 'mediumslateblue', 'mediumspringgreen', 'mediumturquoise',
    'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace',
    'olive', 'olivedrab', 'orange', 'orangered', 'orchid', 'palegoldenrod', 'palegreen', 'paleturquoise',
    'palevioletred', 'papayawhip', 'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple', 'rebeccapurple',
    'red', 'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown', 'seagreen', 'seashell', 'sienna',
    'silver', 'skyblue', 'slateblue', 'slategray', 'slategrey', 'snow', 'springgreen', 'steelblue', 'tan', 'teal',
    'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white', 'whitesmoke', 'yellow', 'yellowgreen',
];

const SYSTEM_COLORS = [
    'accentcolor', 'accentcolortext', 'activeborder', 'activecaption', 'activetext', 'appworkspace', 'background',
    'buttonborder', 'buttonface', 'buttonhighlight', 'buttonshadow', 'buttontext', 'canvas', 'canvastext',
    'captiontext', 'field', 'fieldtext', 'graytext', 'highlight', 'highlighttext', 'inactiveborder',
    'inactivecaption', 'inactivecaptiontext', 'infobackground', 'infotext', 'linktext', 'mark', 'marktext', 'menu',
    'menutext', 'scrollbar', 'selecteditem', 'selecteditemtext', 'threeddarkshadow', 'threedface',
    'threedhighlight', 'threedlightshadow', 'threedshadow', 'visitedtext', 'window', 'windowframe', 'windowtext',
];
