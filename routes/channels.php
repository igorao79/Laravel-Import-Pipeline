<?php

use Illuminate\Support\Facades\Broadcast;

/**
 * Авторизация для приватного WebSocket канала.
 * Пользователь может слушать только свой канал импортов.
 */
Broadcast::channel('imports.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
