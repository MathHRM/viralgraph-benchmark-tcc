<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocialApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['benchmark.reference_date' => '2026-11-10']);
    }

    public function test_user_creation_returns_the_contract_representation_and_location(): void
    {
        $response = $this->postJson('/usuarios', [
            'nome' => 'Ana Souza',
            'apelido' => 'ana',
            'nascimento' => '2000-01-15',
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure(['id', 'nome', 'apelido', 'nascimento'])
            ->assertJsonPath('nome', 'Ana Souza')
            ->assertJsonPath('apelido', 'ana')
            ->assertHeader('Location', '/usuarios/'.$response->json('id'));

        $this->assertDatabaseHas('users', ['nickname' => 'ana']);
    }

    public function test_nicknames_are_unique_but_case_and_accent_sensitive(): void
    {
        foreach (['Ana', 'ana', 'aná'] as $nickname) {
            $this->postJson('/usuarios', [
                'nome' => 'Pessoa',
                'apelido' => $nickname,
                'nascimento' => '2000-01-15',
            ])->assertCreated();
        }

        $this->postJson('/usuarios', [
            'nome' => 'Outra Pessoa',
            'apelido' => 'Ana',
            'nascimento' => '2000-01-15',
        ])->assertUnprocessable();
    }

    public function test_follow_creation_returns_a_relation_and_rejects_duplicates(): void
    {
        $follower = $this->createUser('follower');
        $followed = $this->createUser('followed');
        $payload = ['seguidor_id' => $follower->id, 'seguido_id' => $followed->id];

        $this->postJson('/seguir-usuario', $payload)
            ->assertCreated()
            ->assertJsonPath('seguidor_id', $follower->id)
            ->assertJsonPath('seguido_id', $followed->id)
            ->assertJsonStructure(['id', 'seguidor_id', 'seguido_id', 'criado_em']);

        $this->postJson('/seguir-usuario', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('follows', 1);
    }

    public function test_post_creation_materializes_feed_for_followers_but_not_author(): void
    {
        $author = $this->createUser('author');
        $follower = $this->createUser('follower');
        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $follower->id,
            'seguido_id' => $author->id,
        ])->assertCreated();

        $response = $this->postJson('/posts', [
            'usuario_id' => $author->id,
            'conteudo' => 'Post de teste',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('usuario_id', $author->id)
            ->assertJsonPath('conteudo', 'Post de teste')
            ->assertJsonStructure(['id', 'usuario_id', 'conteudo', 'criado_em'])
            ->assertHeader('Location', '/posts/'.$response->json('id'));

        $this->getJson('/posts/'.$response->json('id'))
            ->assertOk()
            ->assertJsonPath('id', $response->json('id'));

        $this->assertDatabaseHas('feed_items', [
            'user_id' => $follower->id,
            'post_id' => $response->json('id'),
            'origin' => 'post',
        ]);
        $this->assertDatabaseMissing('feed_items', [
            'user_id' => $author->id,
            'post_id' => $response->json('id'),
        ]);
    }

    public function test_post_content_preserves_external_whitespace(): void
    {
        $author = $this->createUser('whitespace-author');

        $this->postJson('/posts', [
            'usuario_id' => $author->id,
            'conteudo' => "  texto preservado \n",
        ])
            ->assertCreated()
            ->assertJsonPath('conteudo', "  texto preservado \n");
    }

    public function test_share_requires_post_in_actor_feed_and_distributes_to_actor_followers(): void
    {
        $author = $this->createUser('author');
        $sharer = $this->createUser('sharer');
        $recipient = $this->createUser('recipient');
        $outsider = $this->createUser('outsider');

        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $sharer->id,
            'seguido_id' => $author->id,
        ])->assertCreated();
        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $recipient->id,
            'seguido_id' => $sharer->id,
        ])->assertCreated();
        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $recipient->id,
            'seguido_id' => $author->id,
        ])->assertCreated();
        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $author->id,
            'seguido_id' => $sharer->id,
        ])->assertCreated();

        $postResponse = $this->postJson('/posts', [
            'usuario_id' => $author->id,
            'conteudo' => 'Original',
        ])->assertCreated();
        $postId = $postResponse->json('id');

        $payload = ['usuario_id' => $sharer->id, 'post_id' => $postId];
        $this->postJson('/compartilhar-post', $payload)
            ->assertCreated()
            ->assertJsonStructure(['id', 'usuario_id', 'post_id', 'criado_em']);

        $this->postJson('/compartilhar-post', $payload)->assertUnprocessable();

        $this->assertDatabaseHas('feed_items', [
            'user_id' => $recipient->id,
            'post_id' => $postId,
            'origin' => 'post',
            'event_author_id' => $author->id,
        ]);
        $this->assertDatabaseCount('feed_items', 2);
        $this->assertDatabaseMissing('feed_items', [
            'user_id' => $author->id,
            'post_id' => $postId,
        ]);
        $this->assertDatabaseMissing('feed_items', [
            'user_id' => $outsider->id,
            'post_id' => $postId,
        ]);
    }

    public function test_search_responses_use_contract_pagination_shape(): void
    {
        $this->createUser('ana');

        $this->getJson('/usuarios?s=an')
            ->assertOk()
            ->assertJsonStructure(['data', 'proximo_cursor'])
            ->assertJsonCount(1, 'data');

        $author = $this->createUser('post-author');
        $this->postJson('/posts', [
            'usuario_id' => $author->id,
            'conteudo' => 'Conteúdo pesquisável',
        ])->assertCreated();

        $this->getJson('/posts?c=pesquisável')
            ->assertOk()
            ->assertJsonStructure(['data', 'proximo_cursor'])
            ->assertJsonCount(1, 'data');
    }

    public function test_search_uses_a_validated_cursor_for_keyset_pagination(): void
    {
        for ($index = 0; $index < 51; $index++) {
            $this->createUser('page-user-'.$index);
        }

        $firstPage = $this->getJson('/usuarios?s=page-user-');
        $firstPage->assertOk()->assertJsonCount(50, 'data');
        $cursor = $firstPage->json('proximo_cursor');
        $this->assertIsString($cursor);

        $this->getJson('/usuarios?s=page-user-&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('proximo_cursor', null);

        $this->getJson('/usuarios?s=page-user-&cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonPath('erro.codigo', 'validacao');
    }

    public function test_uuid_path_parameter_is_validated_and_missing_resource_is_404(): void
    {
        $this->getJson('/usuarios/not-a-uuid')
            ->assertUnprocessable()
            ->assertJsonPath('erro.codigo', 'validacao');

        $this->getJson('/usuarios/00000000-0000-7000-8000-000000000000')
            ->assertNotFound()
            ->assertJsonPath('erro.codigo', 'nao_encontrado');
    }

    public function test_user_text_is_trimmed_and_normalized_to_unicode_nfc(): void
    {
        $response = $this->postJson('/usuarios', [
            'nome' => "  Ana\u{0301} Souza  ",
            'apelido' => "  a\u{0301}na  ",
            'nascimento' => '2000-01-15',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('nome', 'Aná Souza')
            ->assertJsonPath('apelido', 'ána');
    }

    public function test_user_search_is_case_and_accent_sensitive_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Collation contract is verified against the MySQL backend.');
        }

        $this->createUser('Ana');
        $this->createUser('ana');
        $this->createUser('aná');

        $this->getJson('/usuarios?s=ana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.apelido', 'ana');
    }

    public function test_collation_migration_refuses_unsafe_rollback_when_nicknames_would_collide(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Collation rollback is verified against the MySQL backend.');
        }

        $this->createUser('Case');
        $this->createUser('case');

        $migration = require database_path(
            'migrations/2026_11_10_000001_make_social_text_collation_case_accent_sensitive.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback should protect now-distinct unique nicknames.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('nickname', $exception->getMessage());
        }

        $column = DB::selectOne(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'nickname'"
        );

        $this->assertSame('utf8mb4_0900_as_cs', $column->COLLATION_NAME);
    }

    public function test_follow_rejects_self_follow_and_unknown_users(): void
    {
        $user = $this->createUser('self-follow');

        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $user->id,
            'seguido_id' => $user->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('erro.codigo', 'validacao');

        $this->postJson('/seguir-usuario', [
            'seguidor_id' => '00000000-0000-7000-8000-000000000000',
            'seguido_id' => $user->id,
        ])
            ->assertNotFound()
            ->assertJsonPath('erro.codigo', 'nao_encontrado');
    }

    public function test_post_creation_with_missing_author_returns_404(): void
    {
        $this->postJson('/posts', [
            'usuario_id' => '00000000-0000-7000-8000-000000000000',
            'conteudo' => 'Post sem autor existente',
        ])
            ->assertNotFound()
            ->assertJsonPath('erro.codigo', 'nao_encontrado');
    }

    public function test_metrics_returns_logical_creation_counts(): void
    {
        $author = $this->createUser('author');
        $follower = $this->createUser('follower');

        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $follower->id,
            'seguido_id' => $author->id,
        ])->assertCreated();
        $this->postJson('/posts', [
            'usuario_id' => $author->id,
            'conteudo' => 'Metrics test',
        ])->assertCreated();

        $this->getJson('/metricas')
            ->assertOk()
            ->assertExactJson([
                'usuarios' => 2,
                'feeds' => 2,
                'relacoes_seguir' => 1,
                'posts_criados' => 1,
                'compartilhamentos' => 0,
                'entradas_feed' => 1,
                'inserts_logicos' => 7,
            ]);
    }

    public function test_invalid_and_unknown_user_fields_use_the_contract_error_shape(): void
    {
        $this->postJson('/usuarios', [
            'nome' => 'Ana',
            'apelido' => 'ana',
            'nascimento' => '2000-01-15',
            'unexpected' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('erro.codigo', 'validacao')
            ->assertJsonPath('erro.campos.unexpected.0', 'Este campo não é permitido.');
    }

    public function test_non_json_content_type_returns_415(): void
    {
        $this->call('POST', '/usuarios', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"nome":"Ana"}')
            ->assertUnsupportedMediaType()
            ->assertJsonPath('erro.codigo', 'tipo_midia_nao_aceito');
    }

    public function test_vendor_json_media_type_is_not_accepted(): void
    {
        $this->call('POST', '/usuarios', [], [], [], [
            'CONTENT_TYPE' => 'application/problem+json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"nome":"Ana"}')
            ->assertUnsupportedMediaType()
            ->assertJsonPath('erro.codigo', 'tipo_midia_nao_aceito');
    }

    public function test_malformed_json_returns_400(): void
    {
        $this->call('POST', '/usuarios', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{malformed')
            ->assertBadRequest()
            ->assertJsonPath('erro.codigo', 'json_malformado');
    }

    public function test_missing_reference_date_fails_closed_as_operational_error(): void
    {
        config(['benchmark.reference_date' => null]);

        $this->postJson('/usuarios', [
            'nome' => 'Ana',
            'apelido' => 'ana',
            'nascimento' => '2000-01-15',
        ])
            ->assertStatus(503)
            ->assertJsonPath('erro.codigo', 'falha_operacional');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_age_validation_uses_calendar_birthday_for_february_29(): void
    {
        config(['benchmark.reference_date' => '2026-02-28']);
        $this->postJson('/usuarios', [
            'nome' => 'Pessoa Bissexta',
            'apelido' => 'leap-before',
            'nascimento' => '2008-02-29',
        ])->assertUnprocessable();

        config(['benchmark.reference_date' => '2026-03-01']);
        $this->postJson('/usuarios', [
            'nome' => 'Pessoa Bissexta',
            'apelido' => 'leap-date',
            'nascimento' => '2008-02-29',
        ])->assertCreated();
    }

    private function createUser(string $nickname): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'nickname' => $nickname,
            'birth_date' => '2000-01-15',
        ]);
    }
}
