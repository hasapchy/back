<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Birhasap API',
    description: 'REST API для системы управления бизнесом Birhasap. Включает управление пользователями, клиентами, заказами, проектами, складом, транзакциями и другими бизнес-процессами.',
)]
#[OA\Server(
    url: '/api',
    description: 'Основной API сервер'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Personal Access Token',
    description: 'Введите токен в формате: Bearer {token}'
)]
#[OA\Tag(
    name: 'Authentication',
    description: 'Эндпоинты для аутентификации и управления токенами'
)]
#[OA\Tag(
    name: 'Users',
    description: 'Управление пользователями системы'
)]
#[OA\Tag(
    name: 'Roles',
    description: 'Управление ролями и разрешениями'
)]
#[OA\Tag(
    name: 'Companies',
    description: 'Управление компаниями'
)]
#[OA\Tag(
    name: 'Clients',
    description: 'Управление клиентами'
)]
#[OA\Tag(
    name: 'Products',
    description: 'Управление товарами и услугами'
)]
#[OA\Tag(
    name: 'Categories',
    description: 'Управление категориями'
)]
#[OA\Tag(
    name: 'Orders',
    description: 'Управление заказами'
)]
#[OA\Tag(
    name: 'Projects',
    description: 'Управление проектами'
)]
#[OA\Tag(
    name: 'Transactions',
    description: 'Управление транзакциями'
)]
#[OA\Tag(
    name: 'Warehouse',
    description: 'Управление складом и товарами'
)]
#[OA\Tag(
    name: 'Cash Registers',
    description: 'Управление кассами'
)]
#[OA\Tag(
    name: 'Sales',
    description: 'Управление продажами'
)]
#[OA\Tag(
    name: 'Tasks',
    description: 'Управление задачами'
)]
#[OA\Tag(
    name: 'Departments',
    description: 'Управление департаментами'
)]
#[OA\Tag(
    name: 'Chat',
    description: 'Чат и сообщения'
)]
#[OA\Tag(
    name: 'App',
    description: 'Общие эндпоинты приложения (валюты, единицы измерения)'
)]
class OpenApi {}
