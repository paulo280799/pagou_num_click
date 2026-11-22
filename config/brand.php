<?php

// Identidade visual única (landing, checkout e painel admin). Os mesmos valores existem como
// variáveis CSS em landing.blade.php e como classes arbitrárias nos views do checkout.
return [
    'primary' => [
        50 => '#E4F7EF', 100 => '#C2EEDD', 200 => '#8FE0C4', 300 => '#55CDA8', 400 => '#19D3A5',
        500 => '#00B389', 600 => '#0D8A6F', 700 => '#0A6F59', 800 => '#00553F', 900 => '#043F31', 950 => '#02261D',
    ],
    // neutros quentes da landing: 50 = --bg, 200 = --line, 500 = --muted, 900/950 = superfícies dark
    'gray' => [
        50 => '#F7F7F4', 100 => '#EFEEE9', 200 => '#E8E6DF', 300 => '#D4D2C9', 400 => '#A3A39A',
        500 => '#6B6A64', 600 => '#4F4E49', 700 => '#3A3B38', 800 => '#2A2D2A', 900 => '#181A18', 950 => '#101110',
    ],
];
