<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuthenticatedUser;
use App\Entity\Product;
use App\Service\CategoryService;
use App\Service\ProductService;
use App\Support\Csrf;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\ImageUploader;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

/**
 * PRD-01 catalogue, plus the per-warehouse stock view required by WH-01.
 */
final class ProductController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly CategoryService $categories,
        private readonly ImageUploader $uploader,
        private readonly Session $session,
        private readonly View $view,
        private readonly Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->requireUser();

        return Response::html($this->view->renderInLayout('product.index', [
            'title' => 'Products',
            'products' => $this->products->list($actor),
        ]));
    }

    public function show(Request $request, string $id): Response
    {
        $this->requireUser();
        $product = $this->products->find((int) $id);

        return Response::html($this->view->renderInLayout('product.show', [
            'title' => $product->name,
            'product' => $product,
            'levels' => $this->products->stockLevels($product->id ?? 0),
            'category' => $product->categoryName,
        ]));
    }

    public function create(Request $request): Response
    {
        $this->requireUser();

        return Response::html($this->view->renderInLayout('product.form', [
            'title' => 'Add product',
            'csrfToken' => $this->csrf->token(),
            'editing' => null,
            'categories' => $this->categories->list(),
            'values' => $this->blankValues(),
            'errors' => [],
        ]));
    }

    public function store(Request $request): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        try {
            $data = $this->validate($request);
            $file = $request->file('image');
            $imagePath = $file === null ? null : $this->uploader->store($file);

            $this->products->create($actor, $data + ['image_path' => $imagePath]);
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $e->errors(), null);
        }

        $this->session->flash('success', 'Product created.');
        return Response::redirect('/products');
    }

    public function edit(Request $request, string $id): Response
    {
        $this->requireUser();
        $product = $this->products->find((int) $id);

        return Response::html($this->view->renderInLayout('product.form', [
            'title' => 'Edit product',
            'csrfToken' => $this->csrf->token(),
            'editing' => $product,
            'categories' => $this->categories->list(),
            'values' => [
                'sku' => $product->sku,
                'name' => $product->name,
                'category_id' => (string) $product->categoryId,
                'unit' => $product->unit,
                'purchase_price' => (string) $product->purchasePrice,
                'selling_price' => (string) $product->sellingPrice,
                'reorder_point' => (string) $product->reorderPoint,
                'is_active' => $product->isActive,
            ],
            'errors' => [],
        ]));
    }

    public function update(Request $request, string $id): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);
        $product = $this->products->find((int) $id);

        try {
            $data = $this->validate($request);
            $this->products->update($actor, (int) $id, $data);

            $file = $request->file('image');
            if ($file !== null) {
                $newPath = $this->uploader->store($file);
                if ($newPath !== null) {
                    $this->products->setImage($actor, (int) $id, $newPath);
                    // Only removed once the replacement is safely stored.
                    $this->uploader->delete($product->imagePath);
                }
            }
        } catch (ValidationException $e) {
            return $this->formWithErrors($request, $e->errors(), $product);
        }

        $this->session->flash('success', 'Product updated.');
        return Response::redirect('/products/' . (int) $id);
    }

    public function toggleActive(Request $request, string $id): Response
    {
        $actor = $this->requireUser();
        $this->csrf->assertValid($request);

        $activate = $request->string('activate') === '1';
        $this->products->setActive($actor, (int) $id, $activate);

        $this->session->flash('success', $activate ? 'Product activated.' : 'Product deactivated.');
        return Response::redirect('/products');
    }

    /**
     * @return array{sku:string,name:string,category_id:int,unit:string,purchase_price:float,
     *               selling_price:float,reorder_point:int,is_active:bool}
     */
    private function validate(Request $request): array
    {
        $data = Validator::validate($request->all(), [
            'sku' => 'required|max:64',
            'name' => 'required|max:190',
            'category_id' => 'required|int|min:1',
            'unit' => 'required|max:20',
            // min:0 is enforced here AND in the service AND by a CHECK constraint.
            // Three layers because a negative price is a data-integrity problem,
            // not merely a form error.
            'purchase_price' => 'required|decimal|min:0',
            'selling_price' => 'required|decimal|min:0',
            'reorder_point' => 'required|int|min:0',
        ]);

        return [
            'sku' => (string) $data['sku'],
            'name' => (string) $data['name'],
            'category_id' => (int) $data['category_id'],
            'unit' => (string) $data['unit'],
            'purchase_price' => (float) $data['purchase_price'],
            'selling_price' => (float) $data['selling_price'],
            'reorder_point' => (int) $data['reorder_point'],
            'is_active' => $request->string('is_active') !== '',
        ];
    }

    /** @param array<string,string> $errors */
    private function formWithErrors(Request $request, array $errors, ?Product $editing): Response
    {
        return Response::html($this->view->renderInLayout('product.form', [
            'title' => $editing === null ? 'Add product' : 'Edit product',
            'csrfToken' => $this->csrf->token(),
            'editing' => $editing,
            'categories' => $this->categories->list(),
            'values' => [
                'sku' => $request->string('sku'),
                'name' => $request->string('name'),
                'category_id' => $request->string('category_id'),
                'unit' => $request->string('unit'),
                'purchase_price' => $request->string('purchase_price'),
                'selling_price' => $request->string('selling_price'),
                'reorder_point' => $request->string('reorder_point'),
                'is_active' => $request->string('is_active') !== '',
            ],
            'errors' => $errors,
        ]), 422);
    }

    /** @return array<string,string|bool> */
    private function blankValues(): array
    {
        return [
            'sku' => '',
            'name' => '',
            'category_id' => '',
            'unit' => 'pcs',
            'purchase_price' => '0',
            'selling_price' => '0',
            'reorder_point' => '0',
            'is_active' => true,
        ];
    }

    private function requireUser(): AuthenticatedUser
    {
        $user = $this->session->user();
        if ($user === null) {
            throw HttpException::unauthorised();
        }

        return $user;
    }
}
