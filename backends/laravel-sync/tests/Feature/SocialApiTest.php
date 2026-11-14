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

    public function test_share_distributes_to_sharer_followers_synchronously(): void
    {
        $author = $this->createUser('author');
        $sharer = $this->createUser('sharer');
        $recipient = $this->createUser('recipient');
        $outsider = $this->createUser('outsider');

        $this->follow($sharer->id, $author->id);
        $this->follow($recipient->id, $sharer->id);
        $this->follow($recipient->id, $author->id);
        $this->follow($author->id, $sharer->id);

        $postId = $this->createPost($author->id, 'Original');

        $payload = ['usuario_id' => $sharer->id];
        $this->postJson('/posts/'.$postId.'/share', $payload)
            ->assertCreated()
            ->assertJsonStructure(['id', 'usuario_id', 'post_id', 'origem_usuario_id', 'criado_em'])
            ->assertJsonPath('origem_usuario_id', $author->id)
            ->assertJsonPath('post_id', $postId);

        $this->postJson('/posts/'.$postId.'/share', $payload)->assertUnprocessable();

        // recipient já recebera pela publicação: a entrega por compartilhamento não duplica.
        $this->assertDatabaseHas('feed_items', [
            'user_id' => $recipient->id,
            'post_id' => $postId,
            'origin' => 'post',
            'event_author_id' => $author->id,
        ]);
        $this->assertDatabaseCount('feed_items', 2);
        $this->assertDatabaseMissing('feed_items', ['user_id' => $author->id, 'post_id' => $postId]);
        $this->assertDatabaseMissing('feed_items', ['user_id' => $outsider->id, 'post_id' => $postId]);
    }

    public function test_share_delivers_second_degree_post_to_followers_of_sharer(): void
    {
        $author = $this->createUser('deg-author');
        $sharer = $this->createUser('deg-sharer');
        $reader = $this->createUser('deg-reader');
        $this->follow($sharer->id, $author->id);
        $this->follow($reader->id, $sharer->id);

        $postId = $this->createPost($author->id, 'Segundo grau');
        $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => $sharer->id])->assertCreated();

        $this->getJson('/feed/'.$reader->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.post.id', $postId)
            ->assertJsonPath('data.0.origem', 'share')
            ->assertJsonPath('data.0.autor_evento_id', $sharer->id);
    }

    public function test_share_validates_author_origin_and_resources(): void
    {
        $author = $this->createUser('val-author');
        $sharer = $this->createUser('val-sharer');
        $other = $this->createUser('val-other');
        $outsider = $this->createUser('val-outsider');
        $this->follow($sharer->id, $author->id);
        $postId = $this->createPost($author->id, 'Validação');

        $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => $outsider->id])
            ->assertCreated(); // presença no feed não é exigida

        $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => $author->id])
            ->assertUnprocessable()->assertJsonPath('erro.codigo', 'validacao');
        $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => $sharer->id, 'origem_usuario_id' => $other->id])
            ->assertUnprocessable();
        $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => $sharer->id, 'origem_usuario_id' => $sharer->id])
            ->assertUnprocessable();
        $this->postJson('/posts/not-a-uuid/share', ['usuario_id' => $sharer->id])->assertUnprocessable();
        $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => '00000000-0000-4000-8000-000000000000'])
            ->assertNotFound();
        $this->postJson('/posts/00000000-0000-4000-8000-000000000000/share', ['usuario_id' => $sharer->id])
            ->assertNotFound();
    }

    public function test_propagation_tree_uses_recursive_cte_up_to_four_levels(): void
    {
        $author = $this->createUser('tree-author');
        $chain = [];
        for ($index = 1; $index <= 5; $index++) {
            $chain[] = $this->createUser('tree-l'.$index);
        }
        $sibling = $this->createUser('tree-sibling');
        $previous = $author->id;
        foreach ($chain as $user) {
            $this->follow($user->id, $previous); // cada nível recebe do anterior
            $previous = $user->id;
        }
        $this->follow($sibling->id, $author->id);
        $postId = $this->createPost($author->id, 'Viral');

        $source = $author->id;
        foreach ($chain as $user) {
            $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => $user->id, 'origem_usuario_id' => $source])
                ->assertCreated();
            $source = $user->id;
        }
        $this->postJson('/posts/'.$postId.'/share', ['usuario_id' => $sibling->id])->assertCreated();

        $response = $this->getJson('/posts/'.$postId.'/propagation-tree')
            ->assertOk()
            ->assertJsonPath('post_id', $postId)
            ->assertJsonPath('autor_id', $author->id)
            ->assertJsonPath('profundidade_maxima', 4)
            ->assertJsonPath('truncado', false);

        $edges = collect($response->json('data'));
        $this->assertCount(5, $edges, 'quatro níveis da cadeia + irmão no nível 1; o 5º nível fica fora');
        $this->assertSame([1, 1, 2, 3, 4], $edges->pluck('nivel')->sort()->values()->all());
        $this->assertSame($chain[2]->id, $edges->firstWhere('usuario_id', $chain[3]->id)['origem_usuario_id']);
        $this->assertNull($edges->firstWhere('usuario_id', $chain[4]->id));

        $this->getJson('/posts/00000000-0000-4000-8000-000000000000/propagation-tree')->assertNotFound();
        $this->getJson('/posts/not-a-uuid/propagation-tree')->assertUnprocessable();
    }

    private function follow(string $followerId, string $followedId): void
    {
        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $followerId,
            'seguido_id' => $followedId,
        ])->assertCreated();
    }

    private function createPost(string $authorId, string $content): string
    {
        return $this->postJson('/posts', [
            'usuario_id' => $authorId,
            'conteudo' => $content,
        ])->assertCreated()->json('id');
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

    public function test_feed_is_ordered_by_persisted_sequence_and_uses_a_keyset_cursor(): void
    {
        $author = $this->createUser('feed-author');
        $follower = $this->createUser('feed-reader');
        $this->postJson('/seguir-usuario', [
            'seguidor_id' => $follower->id,
            'seguido_id' => $author->id,
        ])->assertCreated();

        for ($index = 0; $index < 51; $index++) {
            $this->postJson('/posts', [
                'usuario_id' => $author->id,
                'conteudo' => 'feed post '.$index,
            ])->assertCreated();
        }

        $firstPage = $this->getJson('/feed/'.$follower->id);
        $firstPage->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.post.conteudo', 'feed post 50')
            ->assertJsonStructure(['data' => [['post', 'origem', 'autor_evento_id', 'evento_id']], 'proximo_cursor']);

        $cursor = $firstPage->json('proximo_cursor');
        $this->assertIsString($cursor);
        $this->getJson('/feed/'.$follower->id.'?cursor='.urlencode($cursor))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.post.conteudo', 'feed post 0')
            ->assertJsonPath('proximo_cursor', null);
    }

    public function test_feed_rejects_invalid_cursor_and_unknown_user(): void
    {
        $user = $this->createUser('feed-cursor');

        $this->getJson('/feed/'.$user->id.'?cursor=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('erro.codigo', 'validacao');

        $this->getJson('/feed/00000000-0000-4000-8000-000000000001')
            ->assertNotFound()
            ->assertJsonPath('erro.codigo', 'nao_encontrado');
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

    public function test_empty_body_returns_422(): void
    {
        $this->call('POST', '/usuarios', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '')
            ->assertUnprocessable()
            ->assertJsonPath('erro.codigo', 'validacao');
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
