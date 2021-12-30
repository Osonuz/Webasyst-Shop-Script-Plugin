<?php

return array(
    'oson_server' => array(
        'value'        => 'https://api.oson.uz/api/invoice/create',
        'title'        => ('Адрес сервера Oson'),
        'description'  => 'Указано в документации OSON',
        'control_type' => waHtmlControl::INPUT,
    ),
    'merchant_idd' => array(
        'value'        => '',
        'title'        => ('ID поставщика'),
        'description'  => 'Получите через электронной почты',
        'control_type' => waHtmlControl::INPUT,
    ),
    'token' => array(
        'value'        => '',
        'title'        => ('Секретный ключ'),
        'description'  => 'Получите через электронной почты',
        'control_type' => waHtmlControl::INPUT,
    ),
	'text_order' => array(
        'value'        => 'Заказ #',
        'title'        => ('Текст комментарий'),
        'description'  => '',
        'control_type' => waHtmlControl::INPUT,
    ),
    'payment_language' => array(
        'value'        => 'ru',
        'title'        => 'Язык платежной формы',
        'description'  => 'Выберите язык платежной формы для Вашего магазина',
        'control_type' => waHtmlControl::SELECT,
        'options'      => array(
            'ru' => "Русский",
            'uz' => "Узбекский",
            'en' => "Английский"
        )
    )
);
