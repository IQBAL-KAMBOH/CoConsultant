<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user registration.
     *
     * @return void
     */
    public function testRegister()
    {
        $userData = [
            'name' => 'M IQBAL',
            'email' => 'theiqbal111@gmail.com',
            'password' => 'password123',
        ];

        $response = $this->json('POST', '/api/register', $userData);

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => 'success',
                     'message' => 'User created successfully',
                 ])
                 ->assertJsonStructure([
                     'status',
                     'message',
                     'user',
                     'authorisation' => [
                         'token',
                     ],
                 ]);
    }

    /**
     * Test user login.
     *
     * @return void
     */
    public function testLogin()
    {
        $user = User::factory()->create([
            'email' => 'john.doe@example.com',
            'password' => Hash::make('password123'),
        ]);

        $loginData = [
            'email' => 'john.doe@example.com',
            'password' => 'password123',
        ];

        $response = $this->json('POST', '/api/login', $loginData);

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => 'success',
                 ])
                 ->assertJsonStructure([
                     'status',
                     'user',
                     'authorisation' => [
                         'token',
                     ],
                 ]);
    }

    /**
     * Test user logout.
     *
     * @return void
     */
    
}
